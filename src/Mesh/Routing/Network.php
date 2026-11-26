<?php

declare(strict_types=1);

namespace Mesh\Routing;

final class Network
{
    public function __construct(private readonly array $model)
    {
    }

    public function build(array $snapshot, float $kg): Graph
    {
        $pvzs = $snapshot['pvzs'];
        $hubs = $snapshot['hubs'];
        $sellers = $snapshot['sellers'] ?? [];
        ksort($pvzs);
        ksort($hubs);
        ksort($sellers);
        $tariffs = $this->model['tariffs'];
        $graph = new Graph();

        foreach ($pvzs as $id => $pvz) {
            $graph->addNode("pvz:$id", $pvz['name'], 'PVZ', $pvz['city_id']);
            $graph->addNode("delivery:$id", $pvz['name'], 'PVZ_DELIVERY', $pvz['city_id']);
        }
        foreach ($hubs as $id => $hub) {
            $graph->addNode("hub-in:$id", $hub['name'], 'HUB_IN', $hub['city_id']);
            $graph->addNode("hub-out:$id", $hub['name'], 'HUB_OUT', $hub['city_id']);
        }
        foreach ($sellers as $id => $seller) {
            $graph->addNode("seller:$id", $seller['name'], 'SELLER', $seller['city_id']);
        }

        $blocked = array_fill_keys($snapshot['blocked_edges'] ?? [], true);
        $overrides = $snapshot['edge_overrides'] ?? [];
        $add = static function (string $from, string $to, string $kind, int $cost, int $minutes, string $label)
            use ($graph, $blocked, $overrides): void {
            $key = "$kind|$from|$to";
            if (isset($blocked[$key])) {
                return;
            }
            $override = $overrides[$key] ?? [];
            $cost = $override['cost_cents'] ?? $cost;
            $minutes = $override['minutes'] ?? $minutes;
            if (!is_int($cost) || !is_int($minutes) || $cost < 0 || $minutes < 0) {
                throw new \InvalidArgumentException("Некорректные веса ребра $key");
            }
            $graph->addEdge($from, $to, $kind, $cost, $minutes, $label);
        };

        $local = static function (array $table, float $km) use ($kg): array {
            return [
                $table['dispatch_cents'] + (int) round($kg * $km * $table['per_kg_km_cents']),
                $table['wait_min'] + (int) ceil($km / $table['kmph'] * 60),
            ];
        };

        foreach ($pvzs as $from => $a) {
            if (!self::active($a)) {
                continue;
            }
            foreach ($pvzs as $to => $b) {
                if ($from === $to || !self::active($b) || $a['city_id'] !== $b['city_id']) {
                    continue;
                }
                $km = self::distance($a, $b);
                if ($km > $this->model['assumptions']['mesh_max_km']) {
                    continue;
                }
                [$cost, $minutes] = $local($tariffs['mesh'], $km);
                $add("pvz:$from", "pvz:$to", 'MESH', $cost, $minutes, 'Рейс между ПВЗ');
            }
        }

        foreach ($hubs as $id => $hub) {
            if (!self::active($hub)) {
                continue;
            }
            $fee = $tariffs['hub'];
            $add("hub-in:$id", "hub-out:$id", 'HANDLING',
                $fee['handling_in_cents'] + $fee['sorting_cents'] + $fee['handling_out_cents'],
                $fee['minutes'], 'Приёмка, сортировка и отгрузка');
            foreach (['pvz' => $pvzs, 'seller' => $sellers] as $kind => $sources) {
                foreach ($sources as $source => $point) {
                    if ($point['city_id'] !== $hub['city_id'] || !self::active($point)) {
                        continue;
                    }
                    $km = self::distance($point, $hub);
                    [$cost, $minutes] = $local($tariffs['first_mile'], $km);
                    $add("$kind:$source", "hub-in:$id", 'FIRST_MILE', $cost, $minutes, 'Доставка на хаб');
                    if ($kind === 'pvz') {
                        [$cost, $minutes] = $local($tariffs['last_mile'], $km);
                        $add("hub-out:$id", "delivery:$source", 'LAST_MILE', $cost, $minutes, 'Последняя миля и ПВЗ выдачи');
                    }
                }
            }
            foreach ($hubs as $to => $other) {
                if ($other['city_id'] === $hub['city_id'] || !self::active($other)) {
                    continue;
                }
                $km = self::distance($hub, $other);
                $trunk = $tariffs['trunk'];
                $add("hub-out:$id", "hub-in:$to", 'TRUNK', (int) round($kg * $km * $trunk['per_kg_km_cents']),
                    $trunk['wait_min'] + (int) ceil($km / $trunk['kmph'] * 60), 'Магистральная перевозка');
            }
        }

        return $graph;
    }

    public static function active(array $point): bool
    {
        return $point['is_active'] ?? true;
    }

    private static function distance(array $a, array $b): float
    {
        $lat1 = deg2rad((float) $a['lat']);
        $lat2 = deg2rad((float) $b['lat']);
        $dlon = deg2rad((float) $b['lng'] - (float) $a['lng']);
        $h = sin(($lat2 - $lat1) / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dlon / 2) ** 2;
        return 6371.0088 * 2 * asin(sqrt(min(1, max(0, $h))));
    }
}
