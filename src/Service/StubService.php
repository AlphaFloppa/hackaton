<?php

declare(strict_types=1);

namespace App\Service;

use App\Infrastructure\Database\ConnectionProvider;
use App\Modules\Notification\Application\NotificationService;
use App\Shared\Api\NotFoundException;

/**
 * Сервис для StubController: логика STUB-эндпоинтов (хаб, магистраль).
 * Фиксированные допущения, без реальной логистики.
 */
final class StubService
{
    public function __construct(
        private readonly ConnectionProvider $db,
        private readonly NotificationService $notifications,
    ) {}

    /** STUB: обработка заказа на хабе (ASSEMBLED -> IN_TRANSIT). */
    public function hubHandle(string $orderId): array
    {
        $order = $this->requireOrder($orderId);
        if ($order['status'] !== 'ASSEMBLED') {
            return ['order_id' => $orderId, 'status' => $order['status'], 'handled' => false];
        }

        $this->db->get()
            ->prepare("UPDATE orders SET status = 'IN_TRANSIT', updated_at = now() WHERE id = ?")
            ->execute([$orderId]);

        $this->notifications->add($order['target_pvz_id'], $orderId, 'IN_TRANSIT', 'Заказ обработан на хабе (stub)');

        return ['order_id' => $orderId, 'status' => 'IN_TRANSIT', 'handled' => true, 'assumption' => 'фиксированная обработка на хабе'];
    }

    /**
     * STUB: магистраль (IN_TRANSIT -> DELIVERED_TO_PVZ).
     * Стоимость = chargeable_kg × км × 0.05₽.
     */
    public function trunkMove(string $orderId, float $chargeableKg, float $distanceKm): array
    {
        $order = $this->requireOrder($orderId);
        if ($order['status'] !== 'IN_TRANSIT') {
            return ['order_id' => $orderId, 'status' => $order['status'], 'moved' => false];
        }

        $cost = number_format($chargeableKg * $distanceKm * 0.05, 2, '.', '');
        $this->db->get()
            ->prepare("UPDATE orders SET status = 'DELIVERED_TO_PVZ', updated_at = now() WHERE id = ?")
            ->execute([$orderId]);

        $this->notifications->add($order['target_pvz_id'], $orderId, 'DELIVERED_TO_PVZ', 'Заказ поступил на ПВЗ (stub магистраль)');

        return [
            'order_id'   => $orderId,
            'status'     => 'DELIVERED_TO_PVZ',
            'moved'      => true,
            'trunk_cost' => $cost,
            'assumption' => 'trunk cost = chargeable_kg × km × 0.05₽',
        ];
    }

    /** Заказ по ID или исключение 404. @return array<string,mixed> */
    private function requireOrder(string $orderId): array
    {
        $stmt = $this->db->get()->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new NotFoundException("Order $orderId not found");
        }

        return $row;
    }
}
