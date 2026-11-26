<?php

declare(strict_types=1);

namespace App\Infrastructure\Routing;

use App\Infrastructure\Database\ConnectionProvider;
use App\Port\Routing\RoutingModuleInterface;
use App\Port\Routing\RoutingDecision;
use App\Shared\Api\NotFoundException;
use Mesh\Routing\NoRoute;
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

    /** @return RoutingDecision */
    public function decide(
        string $orderId,
        string $originNodeId,
        string $targetPvzId,
        array $lines,
        string $preference,
    ): RoutingDecision {
        $line = $lines[0] ?? throw new \App\Shared\Api\ValidationException('Routing requires at least one line');

        $snapshot = $this->snapshot((string) $line['seller_id']);
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
        // Продавец может создать заказ в любой момент: если остатков в сети нет,
        // источник — склад продавца (kind SELLER). ПВЗ примет партию по завозу позже.
        if ($snapshot['stock'] === []) {
            $snapshot['stock'][] = [
                'batch_id' => '00000000-0000-4000-8000-seller-virtual',
                'item_id' => $item['id'], 'kind' => 'SELLER', 'location_id' => (string) $line['seller_id'],
                'available_qty' => (int) $line['qty'],
                'received_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ];
        }

        $request = [
            'item_id' => $item['id'],
            'qty' => (int) $line['qty'],
            'target_pvz_id' => $targetPvzId,
            'preference' => $preference,
        ];

        $plan = (new Router(require self::MODEL_PATH))->plan($request, $snapshot);

        if ($plan['selected'] === null) {
            $quote = $plan['quote'];
            throw new \App\Shared\Api\ConflictException($quote['reason']);
        }

        $quote = $plan['quote'];
        $key = $quote['chosen'] === 'MESH' ? 'mesh' : 'hub';
        $chosen = $quote[$key];

        // Сегменты в форме RoutingDecision (форма UI-контракта: n/l/is_stub/e собираются ниже во FrontendService)
        $segments = [];
        foreach ($chosen['segs'] as $i => $seg) {
            $segments[] = [
                'seq' => $i + 1,
                'mode' => $seg['mode'] ?? ($quote['chosen'] === 'MESH' ? 'LOCAL_MESH' : 'TRUNK'),
                'from_node' => $seg['from'] ?? '',
                'to_node' => $seg['to'] ?? '',
                'distance_km' => '0.00',
                'time_min' => '0.00',
                'cost_transport' => '0.00',
                'cost_handling' => '0.00',
                'cost_hub_fee' => '0.00',
                'cost_risk' => '0.00',
                'is_stub' => (bool) ($seg['is_stub'] ?? false),
                'breakdown' => ['n' => $seg['n'] ?? '', 'l' => $seg['l'] ?? '', 'e' => $seg['e'] ?? 0],
            ];
        }

        $cost = (float) ($chosen['cost'] ?? 0);
        $hours = (float) ($chosen['hours'] ?? 0);
        $hub = $quote['hub'];

        return new RoutingDecision(
            scheme: $quote['chosen'],
            reason: $quote['reason'],
            costMesh: $quote['mesh']['ok'] ? number_format((float) $quote['mesh']['cost'], 2, '.', '') : null,
            costHub: $hub['ok'] ? number_format((float) $hub['cost'], 2, '.', '') : null,
            timeMesh: $quote['mesh']['ok'] ? number_format((float) $quote['mesh']['hours'], 2, '.', '') : null,
            timeHub: $hub['ok'] ? number_format((float) $hub['hours'], 2, '.', '') : null,
            segments: $segments,
        );
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
            $used = (int) $p['capacity_units'];
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
            // used_units считается по фактическим партиям ниже, placeholder
            $pvzs[$p['id']]['_cap'] = $used;
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

        $stmt = $pdo->prepare('SELECT c.id, c.code, c.name FROM cities c JOIN sellers s ON s.city_id = c.id WHERE s.id = ?');
        $stmt->execute([$sellerId]);
        $sellers = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $s) {
            // координаты города как источник первой мили (у продавца в БД нет lat/lng)
            $stmt2 = $pdo->prepare('SELECT lat, lng FROM pvz WHERE city_id = ? LIMIT 1');
            $stmt2->execute([$s['id']]);
            $geo = $stmt2->fetch(\PDO::FETCH_ASSOC) ?: ['lat' => 0, 'lng' => 0];
            $sellers[$sellerId] = [
                'name' => $s['name'], 'city_id' => $s['id'],
                'lat' => (float) $geo['lat'], 'lng' => (float) $geo['lng'], 'is_active' => true,
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

        // Партии с доступным остатком (за вычетом резерва)
        $stmt = $pdo->prepare(
            "SELECT b.id, b.item_id, b.pvz_id, b.qty_available - b.qty_reserved AS avail, b.received_at
             FROM stock_batches b WHERE b.qty_available - b.qty_reserved > 0"
        );
        $stmt->execute();
        $stock = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $b) {
            $stock[] = [
                'batch_id' => $b['id'], 'item_id' => $b['item_id'], 'kind' => 'PVZ',
                'location_id' => $b['pvz_id'], 'available_qty' => (int) $b['avail'],
                'received_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($b['received_at'])),
            ];
            if (isset($pvzs[$b['pvz_id']])) {
                $pvzs[$b['pvz_id']]['used_units'] += (int) $b['avail'];
            }
        }

        return ['items' => $items, 'pvzs' => $pvzs, 'hubs' => $hubs, 'sellers' => $sellers, 'stock' => $stock];
    }
}
