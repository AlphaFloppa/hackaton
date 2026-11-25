<?php

declare(strict_types=1);

namespace App\Port\Routing;

use App\Infrastructure\Database\ConnectionProvider;

/**
 * Stub-реализация контракта маршрутизации. Заменяется настоящим модулем
 * (сервис-контейнер: алиас App\Port\Routing\RoutingModuleInterface).
 * Логика тривиальна: один ПВЗ в городе → MESH, иначе HUB_TRUNK.
 */
final class StubRoutingModule implements RoutingModuleInterface
{
    public function __construct(
        private readonly ConnectionProvider $db,
    ) {}

    /** Решение о схеме доставки (контракт модуля маршрутизации). */
    public function decide(
        string $orderId,
        string $originNodeId,
        string $targetPvzId,
        array $lines,
        string $preference,
    ): RoutingDecision {
        $stmt = $this->db->get()->prepare('SELECT city_id FROM pvz WHERE id = ?');
        $stmt->execute([$targetPvzId]);
        $pvz = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($pvz === false) {
            throw new \App\Shared\Api\NotFoundException('Target PVZ not found');
        }

        $scheme = str_starts_with($originNodeId, 'PVZ:') && $pvz['city_id'] !== null ? 'MESH' : 'HUB_TRUNK';
        $reason = $scheme === 'MESH'
            ? 'Городской заказ: городская mesh-сеть дешевле и быстрее магистрали'
            : 'Межгород: доставка через хаб и магистраль';

        return new RoutingDecision(
            scheme: $scheme,
            reason: $reason,
            costMesh: $scheme === 'MESH' ? '150.00' : null,
            costHub: $scheme === 'HUB_TRUNK' ? '350.00' : null,
            timeMesh: $scheme === 'MESH' ? '8.00' : null,
            timeHub: $scheme === 'HUB_TRUNK' ? '48.00' : null,
            segments: [
                [
                    'seq'            => 1,
                    'mode'           => $scheme === 'MESH' ? 'LOCAL_MESH' : 'SELLER_TO_HUB',
                    'from_node'      => $originNodeId,
                    'to_node'        => 'PVZ:' . $targetPvzId,
                    'distance_km'    => '25.00',
                    'time_min'       => $scheme === 'MESH' ? '120.00' : '600.00',
                    'cost_transport' => $scheme === 'MESH' ? '100.00' : '250.00',
                    'cost_handling'  => '20.00',
                    'cost_hub_fee'   => $scheme === 'MESH' ? '0.00' : '50.00',
                    'cost_risk'      => '30.00',
                    'is_stub'        => true,
                    'breakdown'      => ['note' => 'stub routing module'],
                ],
            ],
        );
    }
}
