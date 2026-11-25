<?php

declare(strict_types=1);

namespace App\Controller;

use App\Infrastructure\Database\ConnectionProvider;
use App\Modules\Notification\Application\NotificationService;
use App\Shared\Api\ApiController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * STUB-эндпоинты внутреннего вызова (Sys): обработка на хабе и магистраль.
 * Фиксированные допущения, без реальной логистики.
 */
final class StubController extends ApiController
{
    public function __construct(
        private readonly ConnectionProvider $db,
        private readonly NotificationService $notifications,
    ) {}

    /** STUB: обработка на хабе: POST /stub/hub/handle. */
    public function hubHandle(Request $request): Response
    {
        return self::handleExceptions(function () use ($request): Response {
            $orderId = self::uuid(self::body($request), 'order_id');
            $order = $this->requireOrder($orderId);

            if ($order['status'] !== 'ASSEMBLED') {
                return $this->json(['order_id' => $orderId, 'status' => $order['status'], 'handled' => false]);
            }

            $this->db->get()
                ->prepare("UPDATE orders SET status = 'IN_TRANSIT', updated_at = now() WHERE id = ?")
                ->execute([$orderId]);

            $this->notifications->add($order['target_pvz_id'], $orderId, 'IN_TRANSIT', 'Заказ обработан на хабе (stub)');

            return $this->json(['order_id' => $orderId, 'status' => 'IN_TRANSIT', 'handled' => true, 'assumption' => 'фиксированная обработка на хабе']);
        });
    }

    /** STUB: магистраль: POST /stub/trunk/move. */
    public function trunkMove(Request $request): Response
    {
        return self::handleExceptions(function () use ($request): Response {
            $body = self::body($request);
            $orderId = self::uuid($body, 'order_id');
            $order = $this->requireOrder($orderId);
            $chargeableKg = max(0.0, (float) ($body['chargeable_kg'] ?? 1.0));
            $km = max(0.0, (float) ($body['distance_km'] ?? 0.0));

            if ($order['status'] !== 'IN_TRANSIT') {
                return $this->json(['order_id' => $orderId, 'status' => $order['status'], 'moved' => false]);
            }

            // STUB: стоимость магистрали = chargeable_kg * km * 0.05 ₽
            $cost = number_format($chargeableKg * $km * 0.05, 2, '.', '');

            $this->db->get()
                ->prepare("UPDATE orders SET status = 'DELIVERED_TO_PVZ', updated_at = now() WHERE id = ?")
                ->execute([$orderId]);

            $this->notifications->add($order['target_pvz_id'], $orderId, 'DELIVERED_TO_PVZ', 'Заказ поступил на ПВЗ (stub магистраль)');

            return $this->json([
                'order_id'   => $orderId,
                'status'     => 'DELIVERED_TO_PVZ',
                'moved'      => true,
                'trunk_cost' => $cost,
                'assumption' => 'trunk cost = chargeable_kg × km × 0.05₽',
            ]);
        });
    }

    /** @return array<string,mixed> */
    private function requireOrder(string $orderId): array
    {
        $stmt = $this->db->get()->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \App\Shared\Api\NotFoundException("Order $orderId not found");
        }

        return $row;
    }
}
