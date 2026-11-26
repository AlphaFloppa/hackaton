<?php

declare(strict_types=1);

namespace App\Infrastructure\Routing;

use App\Infrastructure\Database\ConnectionProvider;
use App\Port\Routing\RoutingModuleInterface;
use App\Port\Routing\RoutingDecision;
use App\Shared\Api\ConflictException;
use App\Shared\Api\NotFoundException;
use App\Shared\Api\ValidationException;
use Mesh\Routing\Router;

/**
 * Реальный модуль маршрутизации Mesh\Routing через порт RoutingModuleInterface.
 * Собирает снимок сети из БД (item, pvzs, hubs, sellers, stock), вызывает Router::plan()
 * и преобразует план в DTO RoutingDecision для существующего контракта.
 */
final class MeshRoutingModule implements RoutingModuleInterface
{
    private const MODEL_PATH = __DIR__ . '/../../../config/mesh/model.php';

    public function __construct(
        private readonly ConnectionProvider $db,
    ) {}

    public function quote(string $targetPvzId, array $lines, string $preference): array
    {
        return $this->plan($targetPvzId, $lines, $preference)['quote'];
    }

    public function decide(
        string $orderId,
        string $originNodeId,
        string $targetPvzId,
        array $lines,
        string $preference,
        ?string $scheme = null,
    ): RoutingDecision {
        $plan = $this->plan($targetPvzId, $lines, $preference, $scheme);
        $quote = $plan['quote'];
        if ($plan['selected'] === null) {
            throw new ConflictException($quote['reason']);
        }

        $key = $quote['chosen'] === 'MESH' ? 'mesh' : 'hub';
        $stops = $quote[$key]['segs'];
        $segments = [];
        foreach ($plan['selected']['path'] as $i => $edge) {
            $stop = $stops[$i + 1];
            if ($i === 0) {
                $stop['start'] = $stops[0];
            }
            $segments[] = [
                'seq' => $i + 1,
                'mode' => match ($edge['kind']) {
                    'MESH' => 'LOCAL_MESH',
                    'TRUNK' => 'TRUNK',
                    'LAST_MILE' => 'LAST_MILE',
                    default => 'SELLER_TO_HUB',
                },
                'from_node' => $edge['from'],
                'to_node' => $edge['to'],
                'distance_km' => '0.00',
                'time_min' => '0.00',
                'cost_transport' => '0.00',
                'cost_handling' => '0.00',
                'cost_hub_fee' => '0.00',
                'cost_risk' => '0.00',
                'is_stub' => (bool) ($stop['is_stub'] ?? false),
                'breakdown' => $stop,
            ];
        }

        return new RoutingDecision(
            scheme: $quote['chosen'],
            reason: $quote['reason'],
            costMesh: $quote['mesh']['ok'] ? number_format($quote['mesh']['cost'], 2, '.', '') : null,
            costHub: $quote['hub']['ok'] ? number_format($quote['hub']['cost'], 2, '.', '') : null,
            timeMesh: $quote['mesh']['ok'] ? number_format($quote['mesh']['hours'], 2, '.', '') : null,
            timeHub: $quote['hub']['ok'] ? number_format($quote['hub']['hours'], 2, '.', '') : null,
            segments: $segments,
        );
    }

    private function plan(string $targetPvzId, array $lines, string $preference, ?string $scheme = null): array
    {
        $line = $lines[0] ?? throw new ValidationException('Routing requires at least one line');

        $snapshot = $this->snapshot((string) $line['seller_id']);
        if (!isset($snapshot['pvzs'][$targetPvzId])) {
            throw new NotFoundException('Целевой ПВЗ не найден.');
        }
        $item = null;
        foreach ($snapshot['items'] as $row) {
            if ($row['sku'] === $line['sku'] || $row['id'] === $line['sku']) {
                $item = $row;
                break;
            }
        }
        if ($item === null) {
            throw new NotFoundException('Item not found for routing');
        }
        $snapshot['item'] = $item;
        $snapshot['stock'] = array_values(array_filter(
            $snapshot['stock'],
            static fn (array $row): bool => $row['item_id'] === $item['id'],
        ));
        // Для HUB_TRUNK продавец привозит товар сам: этот источник не зависит от остатков ПВЗ.
        $snapshot['stock'][] = [
            'batch_id' => '00000000-0000-4000-8000-seller-virtual',
            'item_id' => $item['id'], 'kind' => 'SELLER', 'location_id' => (string) $line['seller_id'],
            'available_qty' => (int) $line['qty'],
        ];

        $request = [
            'item_id' => $item['id'],
            'qty' => (int) $line['qty'],
            'target_pvz_id' => $targetPvzId,
            'preference' => $preference,
            'scheme' => $scheme,
        ];

        try {
            return (new Router(require self::MODEL_PATH))->plan($request, $snapshot);
        } catch (\InvalidArgumentException $e) {
            throw new ValidationException($e->getMessage());
        }
    }

    /**
     * Снимок сети для товара продавца: ПВЗ с загрузкой, хабы, продавец, партии с остатками.
     * @return array<string,mixed>
     */
    private function snapshot(string $sellerId): array
    {
        $pdo = $this->db->get();

        $stmt = $pdo->prepare('SELECT * FROM pvz');
        $stmt->execute();
        $pvzs = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $p) {
            $pvzs[$p['id']] = [
                'name' => $p['name'], 'city_id' => $p['city_id'], 'address' => $p['address'],
                'lat' => (float) $p['lat'], 'lng' => (float) $p['lng'],
                'capacity_units' => (int) $p['capacity_units'],
                'used_units' => 0, // заполняется по партиям ниже
                'is_active' => (bool) $p['is_active'],
                'sla_hours_city' => (int) $p['sla_hours_city'],
                'sla_hours_inter' => (int) $p['sla_hours_inter'],
                'fees' => [
                    'intake_unit_cents' => (int) round($p['fee_intake'] * 100),
                    'assembly_order_cents' => (int) round($p['fee_assembly'] * 100),
                    'issue_order_cents' => (int) round($p['fee_issue'] * 100),
                    'storage_unit_day_cents' => (int) round($p['fee_storage_day'] * 100),
                ],
            ];
        }

        $stmt = $pdo->prepare('SELECT * FROM items WHERE seller_id = ?');
        $stmt->execute([$sellerId]);
        $items = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $i) {
            $items[$i['sku']] = [
                'id' => $i['id'], 'name' => $i['title'], 'sku' => $i['sku'],
                'kg' => (float) $i['weight_kg'], 'volume_m3' => (float) $i['volume_m3'],
            ];
        }

        $stmt = $pdo->prepare('SELECT s.city_id, s.name FROM sellers s WHERE s.id = ? AND s.city_id IS NOT NULL');
        $stmt->execute([$sellerId]);
        $sellers = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $s) {
            $sellers[$sellerId] = [
                'name' => $s['name'], 'city_id' => $s['city_id'], 'is_active' => true,
            ];
        }

        $stmt = $pdo->query('SELECT * FROM hubs');
        $hubs = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $h) {
            $stmt2 = $pdo->prepare('SELECT lat, lng FROM pvz WHERE city_id = ? LIMIT 1');
            $stmt2->execute([$h['city_id']]);
            $geo = $stmt2->fetch(\PDO::FETCH_ASSOC) ?: ['lat' => 0, 'lng' => 0];
            $hubs[$h['id']] = [
                'name' => $h['name'], 'city_id' => $h['city_id'],
                'lat' => (float) $geo['lat'], 'lng' => (float) $geo['lng'], 'is_active' => true,
            ];
        }

        // qty_available уже уменьшен на резерв; для вместимости учитываются и зарезервированные единицы.
        $stmt = $pdo->query(
            "SELECT id, item_id, pvz_id, qty_available, qty_reserved, received_at, status
             FROM stock_batches
             WHERE status IN ('ON_STORAGE', 'DELIVERED', 'IN_TRANSIT') AND qty_available + qty_reserved > 0"
        );
        $stock = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $b) {
            $pointId = $b['pvz_id'];
            $pvzs[$pointId]['used_units'] += (int) $b['qty_available'] + (int) $b['qty_reserved'];
            if ($b['status'] === 'IN_TRANSIT' || (int) $b['qty_available'] === 0 || !$pvzs[$pointId]['is_active']) {
                continue;
            }
            $stock[] = [
                'batch_id' => $b['id'], 'item_id' => $b['item_id'], 'kind' => 'PVZ',
                'location_id' => $pointId, 'available_qty' => (int) $b['qty_available'],
                'received_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($b['received_at'])),
            ];
        }

        return ['items' => $items, 'pvzs' => $pvzs, 'hubs' => $hubs, 'sellers' => $sellers, 'stock' => $stock];
    }
}
