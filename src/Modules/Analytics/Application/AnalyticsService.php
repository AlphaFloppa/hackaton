<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Application;

use App\Infrastructure\Database\ConnectionProvider;
use App\Shared\Api\NotFoundException;

/**
 * Аналитика: cost/profit продавца и ПВЗ на основе ledger_entries, order_calcs, order_segments.
 */
final class AnalyticsService
{
    public function __construct(
        private readonly ConnectionProvider $db,
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
                    COALESCE(SUM(CASE WHEN status = 'COMPLETED' THEN 1 ELSE 0 END), 0) AS completed_cnt,
                    COALESCE(SUM(CASE WHEN status = 'RETURNED' THEN 1 ELSE 0 END), 0) AS returned_cnt,
                    COALESCE(AVG(EXTRACT(EPOCH FROM (updated_at - created_at)) / 3600) FILTER (WHERE status = 'COMPLETED'), 0) AS avg_completion_hours
             FROM orders WHERE seller_id = ?"
        );
        $orders->execute([$sellerId]);
        $summary = $orders->fetch(\PDO::FETCH_ASSOC) ?: [];

        // Расходы на логистику из order_calcs
        $calcs = $pdo->prepare(
            "SELECT COALESCE(SUM(seller_costs), 0) AS seller_costs,
                    COALESCE(SUM(seller_result), 0) AS seller_result,
                    COALESCE(SUM(mp_result), 0) AS mp_result,
                    COALESCE(SUM(pvz_result), 0) AS pvz_result
             FROM order_calcs c JOIN orders o ON o.id = c.order_id
             WHERE o.seller_id = ?"
        );
        $calcs->execute([$sellerId]);
        $calc = $calcs->fetch(\PDO::FETCH_ASSOC) ?: [];

        // base (HUB_TRUNK) vs mesh сравнение по routing_decisions
        $routing = $pdo->prepare(
            "SELECT d.scheme, COUNT(*) AS cnt,
                    COALESCE(AVG(COALESCE(cost_mesh, cost_hub)), 0) AS avg_cost,
                    COALESCE(AVG(COALESCE(time_mesh, time_hub)), 0) AS avg_time
             FROM routing_decisions d JOIN orders o ON o.id = d.order_id
             WHERE o.seller_id = ? GROUP BY d.scheme"
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

        return [
            'seller_id'        => $sellerId,
            'orders'           => $summary,
            'economics'        => $calc,
            'routing_by_scheme' => $routingByScheme,
            'logistics_by_mode' => $segmentsByMode,
            'note'             => 'Все тарифы — допущения, не тарифы RWB',
        ];
    }

    /** Сводка заказов через ПВЗ + проводки + загрузка склада. */
    public function pvzAnalytics(string $pvzId): array
    {
        $stmt = $this->db->get()->prepare('SELECT 1 FROM pvz WHERE id = ?');
        $stmt->execute([$pvzId]);
        if ($stmt->fetchColumn() === false) {
            throw new NotFoundException("PVZ $pvzId not found");
        }

        $pdo = $this->db->get();

        // Финансы ПВЗ из ledger
        $ledger = $pdo->prepare(
            "SELECT entry_type,
                    COALESCE(SUM(amount), 0) AS total,
                    COUNT(*) AS cnt
             FROM ledger_entries WHERE pvz_id = ?
             GROUP BY entry_type ORDER BY entry_type"
        );
        $ledger->execute([$pvzId]);
        $fees = $ledger->fetchAll(\PDO::FETCH_ASSOC);

        // Заказы через ПВЗ
        $orders = $pdo->prepare(
            "SELECT COUNT(*) AS orders_cnt,
                    COALESCE(SUM(CASE WHEN status = 'COMPLETED' THEN 1 ELSE 0 END), 0) AS completed_cnt,
                    COALESCE(SUM(CASE WHEN status = 'RETURNED' THEN 1 ELSE 0 END), 0) AS returned_cnt,
                    COALESCE(AVG(delivery_cost), 0) AS avg_delivery_cost
             FROM orders WHERE target_pvz_id = ?"
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

        $cap = $pdo->prepare('SELECT capacity_units FROM pvz WHERE id = ?');
        $cap->execute([$pvzId]);
        $capacity = (int) $cap->fetchColumn();

        return [
            'pvz_id'   => $pvzId,
            'fees'     => $fees,
            'orders'   => $summary,
            'storage'  => [
                'capacity_units' => $capacity,
                'used_units'     => (int) ($stockRow['used_units'] ?? 0),
                'batches_cnt'    => (int) ($stockRow['batches_cnt'] ?? 0),
                'load_percent'   => $capacity > 0
                    ? round((int) ($stockRow['used_units'] ?? 0) / $capacity * 100, 1)
                    : 0,
            ],
            'note'     => 'Все тарифы — допущения, не тарифы RWB',
        ];
    }
}
