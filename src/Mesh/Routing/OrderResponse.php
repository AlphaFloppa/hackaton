<?php

declare(strict_types=1);

namespace Mesh\Routing;

final class OrderResponse
{
    public static function create(string $orderId, string $status, array $plan): array
    {
        $data = self::fromPlan($plan);
        return ['id' => $orderId, 'status' => $status, ...$data, 'incidents' => []];
    }

    /**
     * Чистое построение ответа после успешного пересчёта. Оплаченные плечи и
     * время инцидента уже входят в progress при вызове Router::plan().
     */
    public static function afterIncident(
        array $previous,
        string $status,
        array $plan,
        string $incidentType,
        int $incidentCostCents,
        int $incidentDelayMinutes,
    ): array {
        if ($incidentCostCents < 0 || $incidentDelayMinutes < 0) {
            throw new \InvalidArgumentException('Затраты и задержка инцидента должны быть неотрицательны.');
        }
        $next = self::fromPlan($plan);
        $next['base'] = $previous['base'];
        $incidents = $previous['incidents'] ?? [];
        $incidents[] = [
            'type' => $incidentType, 'from' => $previous['chosen'], 'to' => $next['chosen'],
            'extra_cost' => Router::rubles($incidentCostCents), 'extra_hours' => Router::hours($incidentDelayMinutes),
        ];

        return [...$previous, ...$next, 'status' => $status, 'incidents' => $incidents];
    }

    private static function fromPlan(array $plan): array
    {
        $quote = $plan['quote'];
        if ($plan['selected'] === null) {
            throw new NoRoute($quote);
        }

        $key = $quote['chosen'] === 'MESH' ? 'mesh' : 'hub';
        $chosen = $quote[$key];

        return [
            'chosen' => $quote['chosen'],
            'cost' => $chosen['cost'],
            'hours' => $chosen['hours'],
            'base' => $quote['base'],
            'sla' => $quote['sla'],
            'reason' => $quote['reason'],
            'segs' => $chosen['segs'],
            'variants' => ['mesh' => $quote['mesh'], 'hub' => $quote['hub']],
            'order_calcs' => $plan['order_calcs'],
        ];
    }
}
