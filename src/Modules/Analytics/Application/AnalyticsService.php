<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Application;

use App\Infrastructure\Database\ConnectionProvider;
use App\Modules\Ledger\Infrastructure\LedgerRepository;
use App\Shared\Api\NotFoundException;

/**
 * Аналитика: cost/profit продавца и ПВЗ на основе ledger_entries, order_calcs, order_segments.
 */
final class AnalyticsService
{
    public function __construct(
        private readonly ConnectionProvider $db,
        private readonly LedgerRepository $ledger,
    ) {}

    /** Сводка по заказам продавца + маршрутизация по схемам. */
    public function sellerAnalytics(string $sellerId): array
    {
        $stmt = $this->db->get()->prepare('SELECT 1 FROM sellers WHERE id = ?');
        $stmt->execute([$sellerId]);
        if ($stmt->fetchColumn() === false) {
            throw new NotFoundException("Seller $sellerId not found");
        }

        $pdo = $this->db->get();

        // Сводка по заказам продавца
        $orders = $pdo->prepare(
            "SELECT COUNT(*) AS orders_cnt,
                    COALESCE(SUM(delivery_cost), 0) AS delivery_revenue,
                    COALESCE(SUM(delivery_cost) FILTER (WHERE status = 'COMPLETED'), 0) AS completed_delivery_revenue,
                    COALESCE(SUM(CASE WHEN status = 'COMPLETED' THEN 1 ELSE 0 END), 0) AS completed_cnt,
                    COALESCE(SUM(CASE WHEN status = 'RETURNED' THEN 1 ELSE 0 END), 0) AS returned_cnt,
                    COALESCE(AVG(EXTRACT(EPOCH FROM (updated_at - created_at)) / 3600) FILTER (WHERE status = 'COMPLETED'), 0) AS avg_completion_hours
             FROM orders WHERE seller_id = ?"
        );
        $orders->execute([$sellerId]);
        $summary = $orders->fetch(\PDO::FETCH_ASSOC) ?: [];

        // Один расчёт на заказ: пересчёты не должны умножать общую сумму.
        $calcs = $pdo->prepare(
            "SELECT COALESCE(SUM(seller_costs), 0) AS seller_costs,
                    COALESCE(SUM(seller_result), 0) AS seller_result,
                    COALESCE(SUM(mp_result), 0) AS mp_result,
                    COALESCE(SUM(pvz_result), 0) AS pvz_result
             FROM (
                 SELECT DISTINCT ON (c.order_id) c.seller_costs, c.seller_result, c.mp_result, c.pvz_result
                 FROM order_calcs c JOIN orders o ON o.id = c.order_id
                 WHERE o.seller_id = ?
                 ORDER BY c.order_id, c.created_at DESC, c.id DESC
             ) latest"
        );
        $calcs->execute([$sellerId]);
        $calc = $calcs->fetch(\PDO::FETCH_ASSOC) ?: [];

        // Сравниваем стоимость трансфера на одних и тех же заказах, без наценки покупателю.
        $routing = $pdo->prepare(
            "SELECT d.scheme, COUNT(*) AS cnt,
                    COALESCE(AVG(d.chosen_cost), 0) AS avg_cost,
                    COALESCE(AVG(d.chosen_time), 0) AS avg_time,
                    COUNT(*) FILTER (WHERE d.cost_hub IS NOT NULL AND d.chosen_cost IS NOT NULL) AS comparable_orders,
                    COALESCE(SUM(d.cost_hub) FILTER (WHERE d.chosen_cost IS NOT NULL), 0) AS base_cost,
                    COALESCE(SUM(d.chosen_cost) FILTER (WHERE d.cost_hub IS NOT NULL), 0) AS chosen_cost
             FROM (
                 SELECT DISTINCT ON (r.order_id) r.scheme, r.cost_hub,
                        CASE WHEN r.scheme = 'MESH' THEN r.cost_mesh ELSE r.cost_hub END AS chosen_cost,
                        CASE WHEN r.scheme = 'MESH' THEN r.time_mesh ELSE r.time_hub END AS chosen_time
                 FROM routing_decisions r JOIN orders o ON o.id = r.order_id
                 WHERE o.seller_id = ?
                 ORDER BY r.order_id, r.created_at DESC, r.id DESC
             ) d
             GROUP BY d.scheme ORDER BY d.scheme"
        );
        $routing->execute([$sellerId]);
        $routingByScheme = $routing->fetchAll(\PDO::FETCH_ASSOC);

        // Логистические затраты продавца по сегментам
        $segments = $pdo->prepare(
            "SELECT s.mode, COUNT(*) AS cnt,
                    COALESCE(SUM(s.cost_transport + s.cost_handling + s.cost_hub_fee + s.cost_risk), 0) AS total_cost,
                    COALESCE(SUM(s.distance_km), 0) AS total_km
             FROM order_segments s JOIN orders o ON o.id = s.order_id
             WHERE o.seller_id = ? GROUP BY s.mode ORDER BY s.mode"
        );
        $segments->execute([$sellerId]);
        $segmentsByMode = $segments->fetchAll(\PDO::FETCH_ASSOC);

        $revenue = (float) $summary['completed_delivery_revenue'];
        $logistics = (float) $calc['seller_costs'];
        $fees = (float) ($this->ledger->sellerTotals($sellerId)['pvz_fees'] ?? 0);
        $base = round(array_sum(array_column($routingByScheme, 'base_cost')), 2);
        $chosen = round(array_sum(array_column($routingByScheme, 'chosen_cost')), 2);
        $saving = round($base - $chosen, 2);

        return [
            'seller_id'        => $sellerId,
            'orders'           => $summary,
            'economics'        => $calc,
            'routing_by_scheme' => $routingByScheme,
            'logistics_by_mode' => $segmentsByMode,
            'financials'       => [
                'orders'            => (int) $summary['orders_cnt'],
                'revenue'           => $revenue,
                'logistics'         => $logistics,
                'pvz_fees'          => $fees,
                'profit'            => round($revenue - $logistics - $fees, 2),
                'comparable_orders' => array_sum(array_column($routingByScheme, 'comparable_orders')),
                'base_cost'         => $base,
                'mesh_cost'         => $chosen,
                'saving'            => $saving,
                'saving_pct'        => $base > 0 ? $saving / $base * 100 : 0,
            ],
            'note'             => 'Все тарифы — допущения, не тарифы RWB',
        ];
    }

    /** Сводка заказов через ПВЗ + проводки + загрузка склада. */
    public function pvzAnalytics(string $pvzId): array
    {
        $stmt = $this->db->get()->prepare('SELECT capacity_units FROM pvz WHERE id = ?');
        $stmt->execute([$pvzId]);
        $capacity = $stmt->fetchColumn();
        if ($capacity === false) {
            throw new NotFoundException("PVZ $pvzId not found");
        }
        $capacity = (int) $capacity;

        $pdo = $this->db->get();

        // Финансы ПВЗ из ledger
        $ledger = $pdo->prepare(
            "SELECT entry_type,
                    COALESCE(SUM(amount), 0) AS total,
                    COALESCE(SUM(CASE WHEN entry_type = 'REFUND' THEN -ABS(amount) ELSE amount END), 0) AS net,
                    COUNT(*) AS cnt
             FROM ledger_entries WHERE pvz_id = ? AND party = 'PVZ'
             GROUP BY entry_type ORDER BY entry_type"
        );
        $ledger->execute([$pvzId]);
        $fees = [];
        $byType = [];
        foreach ($ledger->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $byType[$row['entry_type']] = (float) $row['net'];
            unset($row['net']);
            $fees[] = $row;
        }

        // Заказы через ПВЗ
        $orders = $pdo->prepare(
            "WITH pvz_orders AS (
                 SELECT id, status, delivery_cost FROM orders WHERE target_pvz_id = ?
             ), latest_calcs AS (
                 SELECT DISTINCT ON (c.order_id) c.order_id, c.pvz_costs
                 FROM order_calcs c JOIN pvz_orders o ON o.id = c.order_id
                 ORDER BY c.order_id, c.created_at DESC, c.id DESC
             )
             SELECT COUNT(*) AS orders_cnt,
                    COALESCE(SUM(CASE WHEN status = 'COMPLETED' THEN 1 ELSE 0 END), 0) AS completed_cnt,
                    COALESCE(SUM(CASE WHEN status = 'RETURNED' THEN 1 ELSE 0 END), 0) AS returned_cnt,
                    COALESCE(AVG(delivery_cost), 0) AS avg_delivery_cost,
                    COUNT(c.pvz_costs) AS orders_with_cost,
                    SUM(c.pvz_costs) AS order_cost
             FROM pvz_orders o LEFT JOIN latest_calcs c ON c.order_id = o.id"
        );
        $orders->execute([$pvzId]);
        $summary = $orders->fetch(\PDO::FETCH_ASSOC) ?: [];

        // Хранение: текущая загрузка
        $stock = $pdo->prepare(
            "SELECT COALESCE(SUM(qty_available + qty_reserved), 0) AS used_units,
                    COUNT(*) AS batches_cnt
             FROM stock_batches WHERE pvz_id = ?"
        );
        $stock->execute([$pvzId]);
        $stockRow = $stock->fetch(\PDO::FETCH_ASSOC) ?: [];

        $charges = [
            'intake'      => $byType['FEE_INTAKE'] ?? 0,
            'assembly'    => $byType['FEE_ASSEMBLY'] ?? 0,
            'issue'       => $byType['FEE_ISSUE'] ?? 0,
            'storage'     => $byType['FEE_STORAGE'] ?? 0,
            'corrections' => $byType['REFUND'] ?? 0,
        ];
        $income = round(array_sum($charges), 2);
        $cost = $summary['order_cost'] !== null && $summary['orders_with_cost'] === $summary['orders_cnt']
            ? (float) $summary['order_cost']
            : null;
        $used = (int) ($stockRow['used_units'] ?? 0);

        return [
            'pvz_id'   => $pvzId,
            'fees'     => $fees,
            'orders'   => $summary,
            'storage'  => [
                'capacity_units' => $capacity,
                'used_units'     => $used,
                'batches_cnt'    => (int) ($stockRow['batches_cnt'] ?? 0),
                'load_percent'   => $capacity > 0
                    ? round($used / $capacity * 100, 1)
                    : 0,
            ],
            'financials' => [
                ...$charges,
                'income'   => $income,
                'cost'     => $cost,
                'profit'   => $cost === null ? null : round($income - $cost, 2),
                'load'     => $used,
                'capacity' => $capacity,
            ],
            'note'     => 'Все тарифы — допущения, не тарифы RWB',
        ];
    }
}
