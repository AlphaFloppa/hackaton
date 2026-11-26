<?php

declare(strict_types=1);

namespace App\Modules\Order\Application;

use App\Modules\Order\Infrastructure\OrderRepository;
use App\Port\Routing\RoutingModuleInterface;
use App\Shared\Api\NotFoundException;
use App\Shared\Api\ValidationException;

/**
 * Экономика заказа (упрощённая, тарифы — допущения):
 * buyer_price = transport + handling + hub_fee + risk + margin;
 * выручки/затраты сторон = распределение buyer_price между PVZ / Seller / Marketplace.
 */
final class OrderService
{
    private const MARGIN_RATE = '0.15'; // наценка маркетплейса

    public function __construct(
        private readonly OrderRepository $orders,
        private readonly RoutingModuleInterface $routing, // чужой модуль через порт
    ) {}

    /** Создание заказа: валидация, вызов модуля маршрутизации через порт, запись заказа/сегментов/расчёта в одной транзакции. */
    public function createOrder(array $body): array
    {
        $itemId = $body['item_id'] ?? '';
        $targetPvzId = $body['target_pvz_id'] ?? '';
        $qty = filter_var($body['qty'] ?? null, FILTER_VALIDATE_INT);
        $preference = strtoupper((string) ($body['preference'] ?? 'COST'));
        $scheme = $body['scheme'] ?? null;

        if (!is_string($itemId) || !is_string($targetPvzId)) {
            throw new ValidationException("Fields 'item_id' and 'target_pvz_id' are required");
        }
        if ($qty === false || $qty < 1) {
            throw new ValidationException("Field 'qty' must be an integer >= 1");
        }
        if (!in_array($preference, ['COST', 'TIME'], true)) {
            throw new ValidationException("Field 'preference' must be COST or TIME");
        }
        if ($scheme !== null && !in_array($scheme, ['MESH', 'HUB_TRUNK'], true)) {
            throw new ValidationException("Field 'scheme' must be MESH or HUB_TRUNK");
        }

        $item = $this->orders->findItem($itemId);
        if ($item === null) {
            throw new NotFoundException("Item $itemId not found");
        }

        $orderId = $this->orders->newUuid();
        $originNodeId = 'SELLER:' . $item['seller_id'];

        // --- Вызов модуля маршрутизации (НЕ наша область, только контракт) ---
        $decision = $this->routing->decide(
            orderId: $orderId,
            originNodeId: $originNodeId,
            targetPvzId: $targetPvzId,
            lines: [[
                'seller_id' => $item['seller_id'],
                'sku'       => $item['sku'],
                'qty'       => $qty,
                'weight_kg' => (string) $item['weight_kg'],
                'volume_m3' => (string) $item['volume_m3'],
            ]],
            preference: $preference,
            scheme: $scheme,
        );

        // Стоимость/срок берём из решения модуля маршрутизации (сегменты стаба могут быть без тарифов)
        $segmentCost = $this->sumSegmentCosts($decision->segments);
        $segmentHours = $this->sumSegmentHours($decision->segments);
        $schemeCost = $decision->scheme === 'MESH' ? $decision->costMesh : $decision->costHub;
        $schemeHours = $decision->scheme === 'MESH' ? $decision->timeMesh : $decision->timeHub;
        $cost = bccomp($segmentCost, '0', 2) === 1 ? $segmentCost : ($schemeCost ?? '0.00');
        $hours = bccomp($segmentHours, '0', 2) === 1 ? $segmentHours : ($schemeHours ?? '0.00');
        $buyerPrice = $this->money(bcadd($cost, bcmul($cost, self::MARGIN_RATE, 4), 2));

        // Распределение выручки/затрат (допущение): PVZ 30% затрат, Seller 50%, MP 20%
        $pvzCosts = $this->money(bcmul($cost, '0.30', 2));
        $sellerCosts = $this->money(bcmul($cost, '0.50', 2));
        $mpCosts = $this->money(bcsub($cost, bcadd($pvzCosts, $sellerCosts, 2), 2));

        $this->orders->transactional(function () use ($orderId, $item, $qty, $targetPvzId, $decision, $buyerPrice, $hours, $pvzCosts, $sellerCosts, $mpCosts, $cost): void {
            $this->orders->insertOrder([
                'id'             => $orderId,
                'seller_id'      => $item['seller_id'],
                'item_id'        => $item['id'],
                'qty'            => $qty,
                'customer_id'    => null,
                'target_pvz_id'  => $targetPvzId,
                'source_batch_id' => null,
                'scheme'         => $decision->scheme,
                'status'         => 'CREATED',
                'delivery_cost'  => $buyerPrice,
                'expected_hours' => $hours,
            ]);
            $this->orders->persistRoutingDecision($orderId, $decision);
            $this->orders->persistSegments($orderId, $decision);
            $this->orders->persistCalc($orderId, $decision->scheme, [
                'buyer_price'    => $buyerPrice,
                'buyer_hours'    => $hours,
                'seller_revenue' => $buyerPrice,
                'seller_costs'   => $sellerCosts,
                'seller_result'  => $this->money(bcsub($buyerPrice, $sellerCosts, 2)),
                'mp_revenue'     => $buyerPrice,
                'mp_costs'       => $mpCosts,
                'mp_result'      => $this->money(bcsub($buyerPrice, $mpCosts, 2)),
                'pvz_revenue'    => $buyerPrice,
                'pvz_costs'      => $pvzCosts,
                'pvz_result'     => $this->money(bcsub($buyerPrice, $pvzCosts, 2)),
                'breakdown'      => [
                    'segments_cost' => $cost,
                    'margin_rate'   => self::MARGIN_RATE,
                    'note'          => 'Тарифы — допущения, не тарифы RWB',
                ],
            ]);
        });

        return [
            'id'             => $orderId,
            'scheme'         => $decision->scheme,
            'reason'         => $decision->reason,
            'status'         => 'CREATED',
            'delivery_cost'  => $buyerPrice,
            'expected_hours' => $hours,
        ];
    }

    /** Полный заказ: статус, решение маршрута, сегменты, расчёт. */
    public function getOrder(string $orderId): array
    {
        $order = $this->orders->requireOrder($orderId);
        $decision = $this->orders->routingDecision($orderId);
        $calc = $this->orders->orderCalc($orderId);

        return [
            'id'              => $order['id'],
            'status'          => $order['status'],
            'scheme'          => $order['scheme'],
            'qty'             => (int) $order['qty'],
            'item_id'         => $order['item_id'],
            'seller_id'       => $order['seller_id'],
            'target_pvz_id'   => $order['target_pvz_id'],
            'delivery_cost'   => $order['delivery_cost'],
            'expected_hours'  => $order['expected_hours'],
            'created_at'      => $order['created_at'],
            'routing'         => $decision,
            'segments'        => $this->orders->orderSegments($orderId),
            'calc'            => $calc,
        ];
    }

    /** @param array<int,array<string,mixed>> $segments */
    private function sumSegmentCosts(array $segments): string
    {
        $sum = '0';
        foreach ($segments as $s) {
            $sum = bcadd($sum, $s['cost_transport'], 2);
            $sum = bcadd($sum, $s['cost_handling'], 2);
            $sum = bcadd($sum, $s['cost_hub_fee'], 2);
            $sum = bcadd($sum, $s['cost_risk'], 2);
        }

        return $sum;
    }

    /** @param array<int,array<string,mixed>> $segments */
    private function sumSegmentHours(array $segments): string
    {
        $sum = '0';
        foreach ($segments as $s) {
            $sum = bcadd($sum, bcdiv($s['time_min'], '60', 2), 2);
        }

        return $sum;
    }

    private function money(string $v): string
    {
        return bcmul($v, '1', 2);
    }
}
