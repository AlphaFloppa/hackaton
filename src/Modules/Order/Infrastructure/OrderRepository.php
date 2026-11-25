<?php

declare(strict_types=1);

namespace App\Modules\Order\Infrastructure;

use App\Infrastructure\Database\ConnectionProvider;
use App\Port\Routing\RoutingDecision;
use App\Shared\Api\NotFoundException;

final class OrderRepository
{
    public function __construct(
        private readonly ConnectionProvider $db,
    ) {}

    /** Генерация UUID v4. */
    public function newUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /** @param array<string,mixed> $order */
    public function insertOrder(array $order): void
    {
        $this->db->get()
            ->prepare('INSERT INTO orders (id, seller_id, item_id, qty, customer_id, target_pvz_id, source_batch_id, scheme, status, delivery_cost, expected_hours)
                       VALUES (:id, :seller_id, :item_id, :qty, :customer_id, :target_pvz_id, :source_batch_id, :scheme, :status, :delivery_cost, :expected_hours)')
            ->execute($order);
    }

    /** Сохраняет решение маршрута (чужой модуль считает — мы только фиксируем результат). */
    public function persistRoutingDecision(string $orderId, RoutingDecision $d): void
    {
        $id = $this->newUuid();
        $stmt = $this->db->get()->prepare(
            'INSERT INTO routing_decisions (id, order_id, scheme, reason, cost_mesh, cost_hub, time_mesh, time_hub)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$id, $orderId, $d->scheme, $d->reason, $d->costMesh, $d->costHub, $d->timeMesh, $d->timeHub]);
    }

    /** Сохранение сегментов маршрута. */
    public function persistSegments(string $orderId, RoutingDecision $d): void
    {
        $stmt = $this->db->get()->prepare(
            'INSERT INTO order_segments (order_id, seq, mode, from_node, to_node, distance_km, time_min,
                                         cost_transport, cost_handling, cost_hub_fee, cost_risk, is_stub, breakdown)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb)'
        );
        foreach ($d->segments as $s) {
            $stmt->execute([
                $orderId, $s['seq'], $s['mode'], $s['from_node'], $s['to_node'],
                $s['distance_km'], $s['time_min'], $s['cost_transport'], $s['cost_handling'],
                $s['cost_hub_fee'], $s['cost_risk'], $s['is_stub'] ? 'true' : 'false',
                json_encode($s['breakdown'] ?? [], JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    /** Сохранение расчёта экономики заказа. */
    public function persistCalc(string $orderId, string $scheme, array $calc): void
    {
        $this->db->get()
            ->prepare('INSERT INTO order_calcs (order_id, scheme, buyer_price, buyer_hours,
                          seller_revenue, seller_costs, seller_result, mp_revenue, mp_costs, mp_result,
                          pvz_revenue, pvz_costs, pvz_result, breakdown)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb)')
            ->execute([
                $orderId, $scheme, $calc['buyer_price'], $calc['buyer_hours'],
                $calc['seller_revenue'], $calc['seller_costs'], $calc['seller_result'],
                $calc['mp_revenue'], $calc['mp_costs'], $calc['mp_result'],
                $calc['pvz_revenue'], $calc['pvz_costs'], $calc['pvz_result'],
                json_encode($calc['breakdown'] ?? [], JSON_UNESCAPED_UNICODE),
            ]);
    }

    /** @return array<string,mixed>|null */
    public function findOrder(string $orderId): ?array
    {
        $stmt = $this->db->get()->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** Заказ по ID или исключение 404. */
    public function requireOrder(string $orderId): array
    {
        $order = $this->findOrder($orderId);
        if ($order === null) {
            throw new NotFoundException("Order $orderId not found");
        }

        return $order;
    }

    /** Обновление статуса заказа (опционально с партией-источником). */
    public function updateStatus(string $orderId, string $status, ?string $sourceBatchId = null): void
    {
        if ($sourceBatchId !== null) {
            $this->db->get()
                ->prepare('UPDATE orders SET status = ?, source_batch_id = ?, updated_at = now() WHERE id = ?')
                ->execute([$status, $sourceBatchId, $orderId]);
        } else {
            $this->db->get()
                ->prepare('UPDATE orders SET status = ?, updated_at = now() WHERE id = ?')
                ->execute([$status, $orderId]);
        }
    }

    public function setStatusIfCurrent(string $orderId, string $from, string $to): bool
    {
        $stmt = $this->db->get()
            ->prepare("UPDATE orders SET status = ?, updated_at = now()
                       WHERE id = ? AND status = ?");

        return $stmt->execute([$to, $orderId, $from]) && $stmt->rowCount() === 1;
    }

    public function setStatusAtLeastDelivered(string $orderId, string $target): bool
    {
        // переход из ASSEMBLED или IN_TRANSIT в целевой статус
        $stmt = $this->db->get()
            ->prepare("UPDATE orders SET status = ?, updated_at = now()
                       WHERE id = ? AND status IN ('ASSEMBLED','IN_TRANSIT','DELIVERED_TO_PVZ')");
        $stmt->execute([$target, $orderId]);

        return $stmt->rowCount() === 1;
    }

    /** @return array<int,array<string,mixed>> */
    public function orderSegments(string $orderId): array
    {
        $stmt = $this->db->get()->prepare(
            'SELECT seq, mode, from_node, to_node, distance_km, time_min,
                    cost_transport, cost_handling, cost_hub_fee, cost_risk, is_stub, breakdown
             FROM order_segments WHERE order_id = ? ORDER BY seq'
        );
        $stmt->execute([$orderId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function orderCalc(string $orderId): ?array
    {
        $stmt = $this->db->get()->prepare(
            'SELECT scheme, buyer_price, buyer_hours, seller_revenue, seller_costs, seller_result,
                    mp_revenue, mp_costs, mp_result, pvz_revenue, pvz_costs, pvz_result, breakdown, created_at
             FROM order_calcs WHERE order_id = ? ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function findItem(string $itemId): ?array
    {
        $stmt = $this->db->get()->prepare('SELECT * FROM items WHERE id = ?');
        $stmt->execute([$itemId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function routingDecision(string $orderId): ?array
    {
        $stmt = $this->db->get()->prepare(
            'SELECT scheme, reason, cost_mesh, cost_hub, time_mesh, time_hub, created_at
             FROM routing_decisions WHERE order_id = ? ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @template T @param callable(\PDO):T $fn @return T */
    public function transactional(callable $fn): mixed
    {
        return $this->db->transactional($fn);
    }
}
