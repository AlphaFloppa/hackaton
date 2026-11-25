<?php

declare(strict_types=1);

namespace App\Port\Routing;

/**
 * DTO результата модуля маршрутизации. Заполняется чужим модулем,
 * мы сохраняем его в routing_decisions и order_segments.
 */
final class RoutingDecision
{
    /**
     * @param string $scheme     MESH|HUB_TRUNK
     * @param string $reason     обоснование выбора
     * @param string|null $costMesh
     * @param string|null $costHub
     * @param string|null $timeMesh
     * @param string|null $timeHub
     * @param array<int,array{
     *     seq:int, mode:string, from_node:string, to_node:string,
     *     distance_km:string, time_min:string,
     *     cost_transport:string, cost_handling:string, cost_hub_fee:string, cost_risk:string,
     *     is_stub:bool, breakdown:array
     * }> $segments
     */
    public function __construct(
        public readonly string $scheme,
        public readonly string $reason,
        public readonly ?string $costMesh,
        public readonly ?string $costHub,
        public readonly ?string $timeMesh,
        public readonly ?string $timeHub,
        public readonly array $segments,
    ) {}
}
