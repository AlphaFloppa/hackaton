<?php

declare(strict_types=1);

namespace App\Service;

use App\Infrastructure\Database\ConnectionProvider;
use Mesh\Routing\NoRoute;
use App\Port\Routing\RoutingModuleInterface;
use App\Shared\Api\NotFoundException;

/**
 * Сервис для FrontendController: адаптер данных под форму демо-фронтенда.
 * Возвращает «плоские» структуры (без обёртки {data}), как их ждёт UI:
 * items{name,kg,vol,price}, pvz{cap,fi,fa,fis,fs,active}, заказы со статусами
 * CREATED/ASSEMBLED/ISSUED/RETURNED, аналитика и уведомления в форме demo-заглушек.
 */
final class FrontendService
{
    /** Продавец по умолчанию для демо-фронтенда. */
    private const DEMO_SELLER_ID = '00000000-0000-0000-0000-5e1100000001';

    /** Соответствие статусов БД статусам UI фронтенда. */
    private const STATUS_MAP = [
        'RESERVED'        => 'CREATED',
        'IN_TRANSIT'      => 'ASSEMBLED',
        'DELIVERED_TO_PVZ' => 'ASSEMBLED',
        'COMPLETED'       => 'ISSUED',
    ];

    public function __construct(
        private readonly ConnectionProvider $db,
        private readonly RoutingModuleInterface $routing,
        private readonly \App\Modules\Stock\Application\SupplyService $supplies,
        private readonly \App\Modules\Catalog\Infrastructure\CatalogRepository $catalog,
    ) {}

    /**
     * Bootstrap: справочники + заказы одним ответом для первого экрана.
     * @return array<string,mixed>
     */
    public function bootstrap(): array
    {
        $pdo = $this->db->get();

        $seller = $this->fetchSeller();

        // ПВЗ в форме UI: cap/fi/fa/fis/fs/active
        $stmt = $pdo->query('SELECT * FROM pvz ORDER BY name');
        $pvz = array_map(fn (array $p): array => [
            'id' => $p['id'], 'city' => $p['city_id'], 'name' => $p['name'],
            'address' => $p['address'], 'lat' => (float) $p['lat'], 'lng' => (float) $p['lng'],
            'cap' => (int) $p['capacity_units'], 'fi' => (float) $p['fee_intake'],
            'fa' => (float) $p['fee_assembly'], 'fis' => (float) $p['fee_issue'],
            'fs' => (float) $p['fee_storage_day'], 'active' => (bool) $p['is_active'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));

        // Товары в форме UI: name/kg/vol/price (цены в БД нет — 0)
        $stmt = $pdo->prepare('SELECT * FROM items WHERE seller_id = ? ORDER BY sku');
        $stmt->execute([self::DEMO_SELLER_ID]);
        $items = array_map(fn (array $i): array => [
            'id' => $i['id'], 'name' => $i['title'], 'kg' => (float) $i['weight_kg'],
            'vol' => (float) $i['volume_m3'], 'price' => 0,
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));

        $orders = $this->orderRows('o.seller_id = ?', [self::DEMO_SELLER_ID]);

        return [
            'seller' => $seller,
            'pvz'    => $pvz,
            'items'  => $items,
            'orders' => $orders,
            'now'    => (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Предварительный расчёт маршрута: обе схемы в форме UI (mesh/hub с segs), источник, блокировки.
     * @param array<string,mixed> $q item_id, qty, target_pvz_id, preference
     * @return array<string,mixed>
     */
    public function quote(array $q): array
    {
        $itemId = (string) ($q['item_id'] ?? '');
        $qty = (int) ($q['qty'] ?? 0);
        $targetPvzId = (string) ($q['target_pvz_id'] ?? '');
        $preference = strtoupper((string) ($q['preference'] ?? 'COST'));

        $pdo = $this->db->get();
        $stmt = $pdo->prepare('SELECT * FROM items WHERE id = ?');
        $stmt->execute([$itemId]);
        $item = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($item === false) {
            throw new NotFoundException("Item $itemId not found");
        }
        $stmt = $pdo->prepare('SELECT * FROM pvz WHERE id = ?');
        $stmt->execute([$targetPvzId]);
        $target = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($target === false) {
            throw new NotFoundException("PVZ $targetPvzId not found");
        }

        // Источник: ПВЗ с наибольшим доступным остатком товара
        $stmt = $pdo->prepare(
            'SELECT p.name, p.id, p.city_id FROM stock_batches b JOIN pvz p ON p.id = b.pvz_id
             WHERE b.item_id = ? AND b.qty_available >= ? ORDER BY b.qty_available DESC LIMIT 1'
        );
        $stmt->execute([$itemId, $qty]);
        $src = $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['name' => 'Склад продавца', 'id' => null, 'city_id' => null];

        // Расчётный вес как в demo: max(вес, объём × 250)
        $ch = max((float) $item['weight_kg'] * $qty, (float) $item['volume_m3'] * $qty * 250);

        // Решение через порт маршрутизации (чужой модуль)
        try {
            $decision = $this->routing->decide(
                orderId: '00000000-0000-4000-8000-000000000000',
                originNodeId: 'SELLER:' . $item['seller_id'],
                targetPvzId: $targetPvzId,
                lines: [[
                    'seller_id' => $item['seller_id'], 'sku' => $item['sku'], 'qty' => $qty,
                    'weight_kg' => (string) $item['weight_kg'], 'volume_m3' => (string) $item['volume_m3'],
                ]],
                preference: $preference,
            );
        } catch (NoRoute|\App\Shared\Api\ConflictException $e) {
            // Блокировка: обе схемы недоступны, возвращаем объяснение (форма контракта модуля)
            $reason = $e instanceof NoRoute ? $e->quote['reason'] : $e->getMessage();
            return [
                'chosen' => null,
                'reason' => $reason,
                'block'  => $reason,
                'mesh'   => [...$this->variantFromArray(null, 'MESH'), 'why' => $reason],
                'hub'    => [...$this->variantFromArray(null, 'HUB_TRUNK'), 'why' => $reason],
            ];
        }

        // Блокировки целевого ПВЗ: недоступен или переполнен
        $load = $this->pvzLoad((string) $target['id']);
        $cap = (int) $target['capacity_units'];
        $full = !$target['is_active'] || $load + $qty > $cap;
        $block = !$target['is_active']
            ? 'Целевой ПВЗ недоступен'
            : ($load + $qty > $cap ? "Целевой ПВЗ заполнен ($load/$cap)" : '');
        $sameCity = $src['city_id'] !== null && $src['city_id'] === $target['city_id'];

        $meshCost = $decision->costMesh !== null ? (float) $decision->costMesh : null;
        $meshHours = $decision->timeMesh !== null ? (float) $decision->timeMesh : null;
        $hubCost = $decision->costHub !== null ? (float) $decision->costHub : null;
        $hubHours = $decision->timeHub !== null ? (float) $decision->timeHub : null;

        $mesh = [
            'scheme' => 'MESH',
            'cost'   => $meshCost,
            'hours'  => $meshHours,
            'ok'     => $meshCost !== null && !$full && $sameCity,
            'why'    => $full ? $block : (!$sameCity ? 'Прямой рейс доступен только внутри города' : 'прямая доставка между ПВЗ'),
            'segs'   => [
                ['n' => $src['name'], 'l' => 'партия'],
                ['n' => $target['name'], 'l' => $this->rub($meshCost) . ' · ' . number_format($meshHours ?? 0, 1) . ' ч', 'e' => 1],
            ],
        ];
        $hub = [
            'scheme' => 'HUB_TRUNK',
            'cost'   => $hubCost,
            'hours'  => $hubHours,
            'ok'     => $hubCost !== null && !$full,
            'why'    => $full ? $block : 'через хаб и магистраль',
            'segs'   => [
                ['n' => $src['name'], 'l' => 'партия'],
                ['n' => 'Хаб', 'l' => 'обработка', 'is_stub' => true],
                ['n' => 'Магистраль', 'l' => $this->rub($hubCost) . ' · ' . number_format($hubHours ?? 0, 1) . ' ч', 'is_stub' => true],
                ['n' => $target['name'], 'l' => 'последняя миля', 'e' => 1],
            ],
        ];

        return [
            'chosen'  => $decision->scheme,
            'mesh'    => $mesh,
            'hub'     => $hub,
            'srcName' => $src['name'],
            'ch'      => $ch,
            'block'   => $block,
            'reason'  => $decision->reason,
        ];
    }

    /** Остатки товара по ПВЗ в форме UI: {pvz: имя, qty, reserved, age_days}. @return array<int,array<string,mixed>> */
    public function itemBatches(string $itemId): array
    {
        $stmt = $this->db->get()->prepare(
            'SELECT p.name, b.qty_available, b.qty_reserved, b.received_at
             FROM stock_batches b JOIN pvz p ON p.id = b.pvz_id
             WHERE b.item_id = ? AND b.qty_available + b.qty_reserved > 0
             ORDER BY b.received_at DESC'
        );
        $stmt->execute([$itemId]);

        return array_map(fn (array $b): array => [
            'pvz'      => $b['name'],
            'qty'      => (int) $b['qty_available'],
            'reserved' => (int) $b['qty_reserved'],
            'age_days' => $this->ageDays($b['received_at']),
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** Остатки и загрузка ПВЗ в форме UI: {load, capacity, rows}. @return array<string,mixed> */
    public function pvzStock(string $pvzId): array
    {
        $pdo = $this->db->get();
        $stmt = $pdo->prepare('SELECT * FROM pvz WHERE id = ?');
        $stmt->execute([$pvzId]);
        $pvz = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($pvz === false) {
            throw new NotFoundException("PVZ $pvzId not found");
        }

        $stmt = $pdo->prepare(
            'SELECT i.title, b.qty_available, b.qty_reserved, b.received_at
             FROM stock_batches b JOIN items i ON i.id = b.item_id
             WHERE b.pvz_id = ? AND b.qty_available + b.qty_reserved > 0
             ORDER BY b.received_at DESC'
        );
        $stmt->execute([$pvzId]);
        $rows = array_map(fn (array $b): array => [
            'item'     => $b['title'],
            'qty'      => (int) $b['qty_available'],
            'reserved' => (int) $b['qty_reserved'],
            'age_days' => $this->ageDays($b['received_at']),
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));

        return ['load' => $this->pvzLoad($pvzId), 'capacity' => (int) $pvz['capacity_units'], 'rows' => $rows];
    }

    /** Аналитика продавца в форме UI: profit/revenue/logistics/pvz_fees/saving/base_cost/mesh_cost. @return array<string,mixed> */
    public function sellerAnalytics(): array
    {
        $pdo = $this->db->get();
        $this->fetchSeller();

        // Выручка: стоимость доставки выданных заказов; логистика: seller_costs из расчётов
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(o.delivery_cost) FILTER (WHERE o.status = 'COMPLETED'), 0) AS revenue,
                    COALESCE(SUM(c.seller_costs), 0) AS logistics,
                    COALESCE(SUM(COALESCE(d.cost_hub, d.cost_mesh, 0)), 0) AS base_cost,
                    COALESCE(SUM(o.delivery_cost), 0) AS mesh_cost
             FROM orders o
             LEFT JOIN order_calcs c ON c.order_id = o.id
             LEFT JOIN routing_decisions d ON d.order_id = o.id
             WHERE o.seller_id = ?"
        );
        $stmt->execute([self::DEMO_SELLER_ID]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        // Начисления ПВЗ по заказам продавца
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM ledger_entries
             WHERE seller_id = ? AND entry_type IN ('FEE_ASSEMBLY','FEE_ISSUE','FEE_STORAGE')"
        );
        $stmt->execute([self::DEMO_SELLER_ID]);
        $fees = (float) $stmt->fetchColumn();

        $revenue = (float) $row['revenue'];
        $logistics = (float) $row['logistics'];
        $base = (float) $row['base_cost'];
        $mesh = (float) $row['mesh_cost'];

        return [
            'orders'     => (int) $row['cnt'],
            'revenue'    => $revenue,
            'logistics'  => $logistics,
            'pvz_fees'   => $fees,
            'profit'     => $revenue - $logistics - $fees,
            'base_cost'  => $base,
            'mesh_cost'  => $mesh,
            'saving'     => $base - $mesh,
            'saving_pct' => $base > 0 ? ($base - $mesh) / $base * 100 : 0,
        ];
    }

    /** Аналитика ПВЗ в форме UI: income/intake/assembly/issue/storage/corrections/cost/profit/load/capacity. @return array<string,mixed> */
    public function pvzAnalytics(string $pvzId): array
    {
        $pdo = $this->db->get();
        $stmt = $pdo->prepare('SELECT capacity_units FROM pvz WHERE id = ?');
        $stmt->execute([$pvzId]);
        $cap = $stmt->fetchColumn();
        if ($cap === false) {
            throw new NotFoundException("PVZ $pvzId not found");
        }

        // Суммы проводок по видам (corrections — REFUND со знаком)
        $stmt = $pdo->prepare(
            "SELECT entry_type, COALESCE(SUM(CASE WHEN entry_type = 'REFUND' THEN -amount ELSE amount END), 0) AS total
             FROM ledger_entries WHERE pvz_id = ? GROUP BY entry_type"
        );
        $stmt->execute([$pvzId]);
        $by = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $by[$r['entry_type']] = (float) $r['total'];
        }

        $income = array_sum($by);
        return [
            'income'      => $income,
            'intake'      => $by['FEE_INTAKE'] ?? 0,
            'assembly'    => $by['FEE_ASSEMBLY'] ?? 0,
            'issue'       => $by['FEE_ISSUE'] ?? 0,
            'storage'     => $by['FEE_STORAGE'] ?? 0,
            'corrections' => $by['REFUND'] ?? 0,
            'cost'        => $income * 0.4,
            'profit'      => $income * 0.6,
            'load'        => $this->pvzLoad($pvzId),
            'capacity'    => (int) $cap,
        ];
    }

    /** Уведомления в форме UI: [{type: ERROR|LONG_STORAGE|INFO, text, t}]. @return array<int,array<string,mixed>> */
    public function notifications(?string $pvzId): array
    {
        $sql = 'SELECT type, message, created_at FROM notifications';
        $params = [];
        if ($pvzId !== null) {
            $sql .= ' WHERE pvz_id = ?';
            $params[] = $pvzId;
        }
        $sql .= ' ORDER BY created_at DESC LIMIT 50';
        $stmt = $this->db->get()->prepare($sql);
        $stmt->execute($params);

        return array_map(fn (array $n): array => [
            'type' => match ($n['type']) {
                'INCIDENT' => 'ERROR',
                'STORAGE'  => 'LONG_STORAGE',
                default    => 'INFO',
            },
            'text' => $n['message'],
            't'    => $this->iso($n['created_at']),
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Детали заказа в форме UI: chosen/cost/base/sla/history/incidents/variants/financials.
     * @return array<string,mixed>
     */
    public function orderDetails(string $orderId): array
    {
        $orders = $this->orderRows('o.id = ?', [$orderId]);
        if (!isset($orders[0])) {
            throw new NotFoundException("Order $orderId not found");
        }
        $order = $orders[0];

        $pdo = $this->db->get();

        // Варианты схем из решения маршрута
        $stmt = $pdo->prepare(
            'SELECT scheme, reason, cost_mesh, cost_hub, time_mesh, time_hub
             FROM routing_decisions WHERE order_id = ? ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute([$orderId]);
        $d = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $variants = [];
        foreach (['MESH', 'HUB_TRUNK'] as $scheme) {
            $costKey = $scheme === 'MESH' ? 'cost_mesh' : 'cost_hub';
            $timeKey = $scheme === 'MESH' ? 'time_mesh' : 'time_hub';
            $cost = isset($d[$costKey]) && $d[$costKey] !== null ? (float) $d[$costKey] : null;
            $hours = isset($d[$timeKey]) && $d[$timeKey] !== null ? (float) $d[$timeKey] : null;
            $variants[strtolower($scheme)] = [
                'scheme' => $scheme,
                'cost'   => $cost,
                'hours'  => $hours,
                'ok'     => $cost !== null,
                'why'    => $d['reason'] ?? '',
                'segs'   => [],
            ];
        }

        // Начисления ПВЗ по заказу
        $stmt = $pdo->prepare(
            'SELECT entry_type, reason, amount FROM ledger_entries WHERE order_id = ? AND pvz_id IS NOT NULL'
        );
        $stmt->execute([$orderId]);
        $feeLines = array_map(fn (array $l): array => [
            'label' => $l['reason'] ?? $l['entry_type'], 'amount' => (float) $l['amount'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
        $pvzFees = array_sum(array_column($feeLines, 'amount'));

        // Инциденты
        $stmt = $pdo->prepare(
            'SELECT type, description, created_at FROM incidents WHERE order_id = ? ORDER BY created_at'
        );
        $stmt->execute([$orderId]);
        $incidents = array_map(fn (array $i): array => [
            'type' => $i['type'], 'at' => $this->iso($i['created_at']), 'detail' => $i['description'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));

        $status = (string) $order['status'];
        $issued = $status === 'ISSUED';
        $cost = (float) $order['cost'];

        $order['variants'] = $variants;
        $order['incidents'] = $incidents;
        $order['financials'] = [
            'revenue'    => $issued ? $cost : 0,
            'pvz_fees'   => $pvzFees,
            'result'     => ($issued ? $cost : 0) - $cost - $pvzFees,
            'cost_lines' => [
                ['label' => 'Логистика по выбранной схеме', 'amount' => $cost],
                ...$feeLines,
            ],
        ];

        return $order;
    }

    // ── Внутренние помощники ────────────────────────────────────────────────

    /**
     * Заказы продавца/заказа в форме UI (JOIN партий, решений маршрута, SLA по городам).
     * @return array<int,array<string,mixed>>
     */
    private function orderRows(string $where, array $params): array
    {
        $sql = "SELECT o.*, b.pvz_id AS source_pvz_id, d.cost_hub, d.cost_mesh,
                       (s.city_id = tp.city_id) AS same_city
                FROM orders o
                LEFT JOIN stock_batches b ON b.id = o.source_batch_id
                LEFT JOIN routing_decisions d ON d.order_id = o.id
                LEFT JOIN sellers s ON s.id = o.seller_id
                LEFT JOIN pvz tp ON tp.id = o.target_pvz_id
                WHERE $where
                ORDER BY o.created_at DESC LIMIT 50";
        $stmt = $this->db->get()->prepare($sql);
        $stmt->execute($params);

        return array_map(function (array $o): array {
            $base = $o['cost_hub'] ?? $o['cost_mesh'];
            return [
                'id'             => $o['id'],
                'seller'         => $o['seller_id'],
                'item'           => $o['item_id'],
                'qty'            => (int) $o['qty'],
                'target'         => $o['target_pvz_id'],
                'source_pvz_id'  => $o['source_pvz_id'] ?? $o['target_pvz_id'],
                'status'         => self::STATUS_MAP[$o['status']] ?? $o['status'],
                'chosen'         => $o['scheme'],
                'cost'           => (float) $o['delivery_cost'],
                'base'           => $base !== null ? (float) $base : 0,
                'hours'          => (float) $o['expected_hours'],
                'sla'            => $o['same_city'] ? 24 : 72,
                'price'          => (float) $o['delivery_cost'],
                'reason'         => '',
                'created'        => $this->iso($o['created_at']),
                'history'        => [['type' => 'CREATED', 'at' => $this->iso($o['created_at'])]],
            ];
        }, $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** Продавец демо (с городом) или 404. @return array{id:string,name:string,city:string|null} */
    private function fetchSeller(): array
    {
        $stmt = $this->db->get()->prepare(
            'SELECT s.id, s.name, c.name AS city
             FROM sellers s LEFT JOIN cities c ON c.id = s.city_id
             WHERE s.id = ?'
        );
        $stmt->execute([self::DEMO_SELLER_ID]);
        $seller = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($seller === false) {
            throw new NotFoundException('Demo seller not found (загрузите test/fixtures.sql)');
        }

        return $seller;
    }

    /** Занятые единицы ПВЗ (доступные + зарезервированные). */
    private function pvzLoad(string $pvzId): int
    {
        $stmt = $this->db->get()->prepare(
            "SELECT COALESCE(SUM(qty_available + qty_reserved), 0) FROM stock_batches WHERE pvz_id = ?"
        );
        $stmt->execute([$pvzId]);

        return (int) $stmt->fetchColumn();
    }

    /** Возраст партии в целых сутках. */
    private function ageDays(string $ts): int
    {
        return (int) floor((time() - strtotime($ts)) / 86400);
    }

    /** Timestamp БД -> ISO 8601. */
    private function iso(string $ts): string
    {
        return (new \DateTimeImmutable($ts))->format(\DateTimeInterface::ATOM);
    }

    /** Форматирование суммы для подписей сегментов. */
    private function rub(?float $n): string
    {
        return number_format($n ?? 0, 2, ',', ' ') . ' ₽';
    }
    // ── Завозы партий (адаптер под UI) ──────────────────────────────────────

    /**
     * Создание завоза продавцом. seller_id берётся из контекста демо (DEMO_SELLER_ID).
     * @param array<string,mixed> $q item_id, pvz_id, qty
     * @return array<string,mixed>
     */
    public function createSupply(array $q, string $idempotencyKey): array
    {
        $itemId = (string) ($q['item_id'] ?? '');
        $pvzId = (string) ($q['pvz_id'] ?? '');
        $qty = (int) ($q['qty'] ?? 0);
        if ($itemId === '' || $pvzId === '' || $qty < 1) {
            throw new \App\Shared\Api\ValidationException("Fields 'item_id', 'pvz_id' (uuid) and 'qty' (int >= 1) are required");
        }

        return $this->supplies->create([
            'seller_id' => self::DEMO_SELLER_ID,
            'item_id' => $itemId,
            'pvz_id' => $pvzId,
            'qty' => $qty,
        ], $idempotencyKey);
    }

    /**
     * Приёмка завоза ПВЗ. @return array<string,mixed>
     */
    public function acceptSupply(string $supplyId, string $idempotencyKey): array
    {
        return $this->supplies->accept($supplyId, $idempotencyKey);
    }

    /**
     * Список завозов в форме UI: для продавца — свои, для ПВЗ — по pvz_id.
     * @return array<int,array<string,mixed>>
     */
    public function suppliesList(?string $pvzId): array
    {
        $sql = 'SELECT s.id, s.item_id, s.pvz_id, s.qty, s.status, s.created_at,
                       i.title AS item_name, p.name AS pvz_name, sl.name AS seller_name
                FROM supplies s
                JOIN items i ON i.id = s.item_id
                JOIN pvz p ON p.id = s.pvz_id
                JOIN sellers sl ON sl.id = s.seller_id';
        $params = [];
        if ($pvzId !== null) {
            $sql .= ' WHERE s.pvz_id = ? OR s.seller_id = ?';
            $params[] = $pvzId;
            $params[] = self::DEMO_SELLER_ID;
        } else {
            $sql .= ' WHERE s.seller_id = ?';
            $params[] = self::DEMO_SELLER_ID;
        }
        $sql .= ' ORDER BY s.created_at DESC LIMIT 50';
        $stmt = $this->db->get()->prepare($sql);
        $stmt->execute($params);

        return array_map(fn (array $s): array => [
            'id' => $s['id'], 'item' => $s['item_name'], 'pvz' => $s['pvz_name'],
            'seller' => $s['seller_name'], 'qty' => (int) $s['qty'],
            'status' => $s['status'], 'created' => $this->iso($s['created_at']),
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }
    /**
     * Создание товара от демо-продавца: адаптирует payload фронтенда (name/kg/vol/price)
     * к спецификационному POST /items. sku = название (уникальность через seller+sku в БД).
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function createItem(array $body): array
    {
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            throw new \App\Shared\Api\ValidationException("Field 'name' is required");
        }

        $id = $this->catalog->createItem(
            sellerId: self::DEMO_SELLER_ID,
            sku: $name,
            title: $name,
            weightKg: number_format((float) ($body['kg'] ?? 0), 3, '.', ''),
            volumeM3: number_format((float) ($body['vol'] ?? 0), 4, '.', ''),
        );

        return ['id' => $id, 'name' => $name];
    }

    /** Вариант схемы из массива модуля в форму UI. @return array<string,mixed> */
    private function variantFromArray(?array $v, string $scheme): array
    {
        return [
            'scheme' => $scheme,
            'ok'     => ($v['ok'] ?? false) === true && isset($v['cost']),
            'cost'   => isset($v['cost']) ? (float) $v['cost'] : null,
            'hours'  => isset($v['hours']) ? (float) $v['hours'] : null,
        ];
    }
}