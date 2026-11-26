<?php

declare(strict_types=1);

namespace Mesh\Routing;

use InvalidArgumentException;

final class Router
{
    private const KINDS = [
        'mesh' => ['MESH'],
        'hub' => ['SELLER_DROPOFF', 'FIRST_MILE', 'HANDLING', 'TRUNK', 'LAST_MILE'],
    ];
    private const SCHEMES = ['mesh' => 'MESH', 'hub' => 'HUB_TRUNK'];

    public function __construct(private readonly array $model)
    {
    }

    /**
     * Чистый расчёт на снимке данных. Резерв остатков и места выполняет backend.
     * При пересчёте progress указывает фактическую позицию, понесённые
     * транспортные затраты и время с момента создания заказа.
     */
    public function plan(array $request, array $snapshot, ?array $progress = null): array
    {
        $item = $snapshot['item'];
        $request = $this->normalize($request, $item);
        $qty = $request['qty'];
        $targetId = $request['target_pvz_id'];
        $target = $snapshot['pvzs'][$targetId] ?? null;
        if ($target === null) {
            throw new InvalidArgumentException('Целевой ПВЗ не найден.');
        }

        $kg = max(
            (float) $item['kg'] * $qty,
            (float) $item['volume_m3'] * $qty * $this->model['assumptions']['volumetric_kg_per_m3'],
        );
        $graph = (new Network($this->model))->build($snapshot, $kg, $qty);
        $sources = $progress === null ? $this->origins($snapshot, $qty) : [$this->position($progress, $snapshot)];
        $spent = $this->integer($progress['spent_cents'] ?? 0, 'spent_cents', 0, 100_000_000_000_000_000);
        $elapsed = $this->integer($progress['elapsed_minutes'] ?? 0, 'elapsed_minutes', 0, 10_000_000);
        $reserved = $this->integer($progress['own_target_reservation'] ?? 0, 'own_target_reservation', 0, $qty);
        $completed = $progress['completed_segs'] ?? [];
        $paths = [];
        $comparators = [];
        $variants = [];
        $free = $target['capacity_units'] - $target['used_units'];
        foreach (self::KINDS as $key => $kinds) {
            $best = null;
            $outsideSla = null;
            $hasSource = false;
            $room = false;
            if (Network::active($target)) {
                foreach ($sources as $source) {
                    $sameCity = $source['city'] === $target['city_id'];
                    if ($key === 'mesh' && (($source['current_city'] ?? $source['city']) !== $target['city_id']
                        || $source['kind'] !== 'PVZ' || $source['location_id'] === $targetId)) {
                        continue;
                    }
                    if ($key === 'hub' && $progress === null && $source['kind'] !== 'SELLER') {
                        continue;
                    }
                    $hasSource = true;
                    $added = $source['node'] === "pvz:$targetId" ? 0 : $qty - $reserved;
                    if ($added > 0 && $added > $free) {
                        continue;
                    }
                    $room = true;
                    $sla = $this->sla($target, $sameCity);
                    $destination = $key === 'mesh' ? "pvz:$targetId" : "delivery:$targetId";
                    $limit = $sla * 60 - $elapsed;
                    $path = $limit < 0 ? null : $graph->shortest($source['node'], $destination, $kinds, $request['preference'], $limit);

                    if ($path !== null) {
                        $candidate = $this->candidate($path, $source, $sla, $spent, $elapsed);
                        $best = $this->better($best, $candidate, $request['preference']);
                        continue;
                    }
                    $path = $graph->shortest($source['node'], $destination, $kinds, 'TIME', null);
                    if ($path !== null) {
                        $candidate = $this->candidate($path, $source, $sla, $spent, $elapsed);
                        $outsideSla = $this->better($outsideSla, $candidate, 'TIME');
                    }
                }
            }

            $paths[$key] = $best;
            $comparators[$key] = $best ?? $outsideSla;
            if ($best !== null) {
                $why = $key === 'hub' ? 'Доставка через хаб' : 'Доставка между ПВЗ одного города';
                if (in_array('TRUNK', array_column($best['path'], 'kind'), true)) {
                    $why = 'Доставка через хабы и магистраль';
                }
                $segments = $this->joinSegments($completed, $graph->segments($best['source']['node'], $best['path']));
                $variants[$key] = [
                    'scheme' => self::SCHEMES[$key], 'cost' => self::rubles($best['cost']),
                    'hours' => self::hours($best['minutes']), 'ok' => true, 'why' => $why, 'segs' => $segments,
                ];
                continue;
            }

            $why = match (true) {
                !Network::active($target) => 'Целевой ПВЗ недоступен.',
                !$hasSource && $key === 'mesh' => "В городе выдачи нет другого активного ПВЗ с доступным остатком от $qty ед.",
                !$hasSource && $progress === null => 'Не указан город продавца.',
                !$hasSource => 'Недостаточно доступного остатка в одной точке отправления.',
                !$room => sprintf('В целевом ПВЗ недостаточно места (%d/%d занято).', $target['used_units'], $target['capacity_units']),
                $outsideSla !== null => sprintf('Даже самый быстрый путь занимает %.1f ч при SLA %d ч.',
                    self::hours($outsideSla['minutes']), $outsideSla['sla']),
                default => 'Связный путь по доступным плечам не найден.',
            };
            $variants[$key] = ['scheme' => self::SCHEMES[$key], 'cost' => null, 'hours' => null, 'ok' => false, 'why' => $why, 'segs' => []];
        }

        $chosenKey = $paths['mesh'] !== null ? 'mesh' : ($paths['hub'] !== null ? 'hub' : null);
        if ($paths['mesh'] !== null && $paths['hub'] !== null
            && Graph::rank($paths['hub'], $request['preference']) < Graph::rank($paths['mesh'], $request['preference'])) {
            $chosenKey = 'hub';
        }

        $reason = $this->reason($chosenKey, $paths, $variants, $request['preference']);
        if ($request['scheme'] !== null) {
            $scheme = $request['scheme'];
            $key = $scheme === 'MESH' ? 'mesh' : 'hub';
            $chosenKey = $paths[$key] === null ? null : $key;
            $reason = $chosenKey === null
                ? "Схема $scheme недоступна. " . $variants[$key]['why']
                : "Схема $scheme выбрана продавцом. " . $variants[$key]['why'];
        }
        $chosen = $chosenKey === null ? null : $paths[$chosenKey];
        $base = $comparators['hub'] === null ? null : self::rubles($comparators['hub']['cost']);
        $calcs = (new Economics($this->model))->compare($request, $snapshot, $comparators, $paths);

        return [
            'request' => $request,
            'quote' => [
                ...$variants,
                'chosen' => $chosenKey === null ? null : self::SCHEMES[$chosenKey],
                'reason' => $reason,
                'block' => $chosenKey === null ? $reason : null,
                'srcName' => $chosen['source']['name'] ?? null,
                'ch' => round($kg, 6),
                'base' => $base,
                'sla' => $chosen['sla'] ?? null,
            ],
            'selected' => $chosen,
            'paths' => $paths,
            'order_calcs' => $calcs,
            'graph' => $graph->snapshot(),
            'model' => $this->model,
        ];
    }

    public static function rubles(int $cents): float
    {
        return $cents / 100;
    }

    public static function hours(int $minutes): float
    {
        return round($minutes / 60, 4);
    }

    private function normalize(array $request, array $item): array
    {
        foreach (['item_id', 'target_pvz_id'] as $key) {
            if (!is_string($request[$key] ?? null) || $request[$key] === '') {
                throw new InvalidArgumentException("Поле $key обязательно.");
            }
        }
        if ($request['item_id'] !== $item['id']) {
            throw new InvalidArgumentException('В снимке отсутствует запрошенный товар.');
        }
        $preference = $request['preference'] ?? 'COST';
        if (!in_array($preference, ['COST', 'TIME'], true)) {
            throw new InvalidArgumentException('Приоритет должен быть COST или TIME.');
        }
        $scheme = $request['scheme'] ?? null;
        if ($scheme !== null && !in_array($scheme, self::SCHEMES, true)) {
            throw new InvalidArgumentException('Схема должна быть MESH или HUB_TRUNK.');
        }
        $kg = $item['kg'] ?? null;
        $volume = $item['volume_m3'] ?? null;
        if (!is_numeric($kg) || !is_numeric($volume)
            || $kg < 0 || $volume < 0 || $kg > 100_000 || $volume > 1000
            || !is_finite((float) $kg) || !is_finite((float) $volume)
            || ((float) $kg === 0.0 && (float) $volume === 0.0)) {
            throw new InvalidArgumentException('Проверьте физический вес и объём товара.');
        }

        return [
            'item_id' => $request['item_id'],
            'target_pvz_id' => $request['target_pvz_id'],
            'qty' => $this->integer($request['qty'] ?? null, 'qty', 1, 1_000_000),
            'preference' => $preference,
            'scheme' => $scheme,
            'storage_days' => $this->integer($request['storage_days'] ?? $this->model['assumptions']['default_storage_days'],
                'storage_days', 0, 3650),
        ];
    }

    private function integer(mixed $value, string $name, int $min, int $max): int
    {
        if ((!is_int($value) && !(is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1))
            || (int) $value < $min || (int) $value > $max) {
            throw new InvalidArgumentException("Поле $name должно быть целым числом от $min до $max.");
        }
        return (int) $value;
    }

    private function origins(array $snapshot, int $qty): array
    {
        $stocks = [];
        foreach ($snapshot['stock'] as $row) {
            $kind = $row['kind'];
            $id = $row['location_id'];
            $point = match ($kind) {
                'PVZ' => $snapshot['pvzs'][$id] ?? null,
                'SELLER' => $snapshot['sellers'][$id] ?? null,
                default => null,
            };
            if ($point === null || !Network::active($point) || $row['available_qty'] <= 0) {
                continue;
            }
            $node = strtolower($kind) . ':' . $id;
            $stocks[$node]['source'] = ['node' => $node, 'kind' => $kind, 'location_id' => $id,
                'city' => $point['city_id'], 'name' => $point['name']];
            $stocks[$node]['rows'][] = $row;
        }

        $sources = [];
        foreach ($stocks as ['source' => $source, 'rows' => $rows]) {
            usort($rows, static fn (array $a, array $b): int =>
                [$a['received_at'] ?? '', $a['batch_id']] <=> [$b['received_at'] ?? '', $b['batch_id']]);
            $remaining = $qty;
            $allocations = [];
            foreach ($rows as $row) {
                $used = min($remaining, (int) $row['available_qty']);
                $allocations[] = ['batch_id' => $row['batch_id'], 'qty' => $used];
                $remaining -= $used;
                if ($remaining === 0) {
                    $sources[] = [...$source, 'allocations' => $allocations];
                    break;
                }
            }
        }
        return $sources;
    }

    private function position(array $progress, array $snapshot): array
    {
        $id = (string) ($progress['location_id'] ?? '');
        $kind = $progress['kind'] ?? '';
        [$prefix, $point] = match ($kind) {
            'PVZ' => ['pvz', $snapshot['pvzs'][$id] ?? null],
            'SELLER' => ['seller', $snapshot['sellers'][$id] ?? null],
            'HUB_IN' => ['hub-in', $snapshot['hubs'][$id] ?? null],
            'HUB_OUT' => ['hub-out', $snapshot['hubs'][$id] ?? null],
            default => ['', null],
        };
        if ($point === null) {
            throw new InvalidArgumentException('Фактическая позиция заказа не найдена.');
        }
        return ['node' => "$prefix:$id", 'kind' => $kind, 'location_id' => $id,
            'city' => $progress['origin_city_id'] ?? $point['city_id'], 'name' => $point['name'], 'allocations' => [],
            'current_city' => $point['city_id']];
    }

    private function sla(array $target, bool $sameCity): int
    {
        $type = $sameCity ? 'city' : 'intercity';
        return $this->integer($target["sla_hours_$type"] ?? $this->model['assumptions']["default_{$type}_sla_hours"],
            "sla_hours_$type", 1, 100_000);
    }

    private function candidate(array $path, array $source, int $sla, int $spent, int $elapsed): array
    {
        return [...$path, 'cost' => $path['cost'] + $spent, 'minutes' => $path['minutes'] + $elapsed,
            'spent_cents' => $spent, 'elapsed_minutes' => $elapsed, 'source' => $source, 'sla' => $sla];
    }

    private function better(?array $previous, array $candidate, string $preference): array
    {
        $key = static fn (array $value): array => [...Graph::rank($value, $preference), $value['source']['node']];
        return $previous === null || $key($candidate) < $key($previous) ? $candidate : $previous;
    }

    private function joinSegments(array $completed, array $next): array
    {
        if ($completed === []) {
            return $next;
        }
        $lastIndex = array_key_last($completed);
        unset($completed[$lastIndex]['e']);
        if ($completed[$lastIndex]['n'] === $next[0]['n']) {
            array_shift($next);
        }
        $segments = [...$completed, ...$next];
        $segments[array_key_last($segments)]['e'] = true;
        return $segments;
    }

    private function reason(?string $chosen, array $paths, array $variants, string $preference): string
    {
        if ($chosen === null) {
            return $variants['mesh']['why'] === $variants['hub']['why']
                ? $variants['mesh']['why']
                : 'MESH: ' . $variants['mesh']['why'] . ' HUB_TRUNK: ' . $variants['hub']['why'];
        }

        $other = $chosen === 'mesh' ? 'hub' : 'mesh';
        $selected = self::SCHEMES[$chosen];
        $alternate = self::SCHEMES[$other];
        if ($paths[$other] === null) {
            return "Выбрана схема $selected. $alternate недоступна. " . $variants[$other]['why'];
        }

        $price = $paths[$other]['cost'] - $paths[$chosen]['cost'];
        $time = $paths[$other]['minutes'] - $paths[$chosen]['minutes'];
        $priceText = number_format(self::rubles($price), 2, ',', ' ');
        if ($preference === 'COST' && $price > 0) {
            return "Выбрана схема $selected: стоимость трансфера ниже на $priceText ₽.";
        }
        if ($time > 0) {
            return sprintf('Выбрана схема %s: доставка быстрее на %.2f ч%s.', $selected,
                self::hours($time), $preference === 'COST' ? ' при одинаковой стоимости' : '');
        }
        if ($price > 0) {
            return "Выбрана схема $selected: при одинаковом сроке стоимость трансфера ниже на $priceText ₽.";
        }
        return "Выбрана схема $selected: стоимость и срок совпадают, учтено количество плеч и фиксированный порядок схем.";
    }
}
