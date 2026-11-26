<?php

declare(strict_types=1);

namespace Mesh\Routing;

use SplPriorityQueue;

final class Graph
{
    private array $nodes = [];
    private array $edges = [];
    private array $outgoing = [];

    public function addNode(string $id, string $name, string $kind, string $city): void
    {
        $this->nodes[$id] = compact('id', 'name', 'kind', 'city');
    }

    public function addEdge(string $from, string $to, string $kind, int $cost, int $minutes, string $label): void
    {
        $id = "$kind|$from|$to";
        $edge = compact('id', 'from', 'to', 'kind', 'cost', 'minutes', 'label');
        $this->edges[$id] = $edge;
        $this->outgoing[$from][] = $edge;
    }

    public function snapshot(): array
    {
        return ['nodes' => array_values($this->nodes), 'edges' => array_values($this->edges)];
    }

    public function segments(string $source, array $path): array
    {
        $start = $this->nodes[$source];
        $segments = [[
            'n' => $start['kind'] === 'SELLER' ? 'Продавец · ' . $start['name'] : $start['name'],
            'l' => $path === [] ? 'Выдача товара из остатков ПВЗ' : 'Точка отправления',
            'kind' => $start['kind'],
        ]];
        if (in_array($start['kind'], ['HUB_IN', 'HUB_OUT'], true)) {
            $segments[0]['is_stub'] = true;
        }
        foreach ($path as $edge) {
            $node = $this->nodes[$edge['to']];
            $segment = ['n' => $node['name'], 'l' => $edge['label']];
            if (in_array($node['kind'], ['HUB_IN', 'HUB_OUT'], true)) {
                $segment['is_stub'] = true;
            }
            $segments[] = $segment;
        }
        $segments[array_key_last($segments)]['e'] = true;
        return $segments;
    }

    public static function rank(array $label, string $preference): array
    {
        return $preference === 'TIME'
            ? [$label['minutes'], $label['cost'], $label['hops']]
            : [$label['cost'], $label['minutes'], $label['hops']];
    }

    /**
     * Алгоритм Дейкстры. При ограничении времени сохраняем недоминируемые
     * метки (цена, время), чтобы дешёвый медленный префикс не вытеснил быстрый.
     */
    public function shortest(
        string $source,
        string $target,
        array $allowedKinds,
        string $preference,
        ?int $limit,
    ): ?array {
        $queue = new SplPriorityQueue();
        $queue->setExtractFlags(SplPriorityQueue::EXTR_DATA);
        $sequence = 0;
        $start = ['id' => $sequence, 'node' => $source, 'cost' => 0, 'minutes' => 0, 'hops' => 0, 'path' => []];
        $queue->insert($start, [0, 0, 0, 0]);
        $best = [$source => [$sequence => $start]];

        while (!$queue->isEmpty()) {
            $label = $queue->extract();
            if (!isset($best[$label['node']][$label['id']])) {
                continue;
            }
            if ($label['node'] === $target) {
                return $label;
            }

            foreach ($this->outgoing[$label['node']] ?? [] as $edge) {
                if (!in_array($edge['kind'], $allowedKinds, true)) {
                    continue;
                }
                $node = $edge['to'];
                $next = [
                    'id' => ++$sequence,
                    'node' => $node,
                    'cost' => $label['cost'] + $edge['cost'],
                    'minutes' => $label['minutes'] + $edge['minutes'],
                    'hops' => $label['hops'] + 1,
                    'path' => [...$label['path'], $edge],
                ];
                if ($limit !== null && $next['minutes'] > $limit) {
                    continue;
                }
                foreach ($best[$node] ?? [] as $other) {
                    if (self::dominates($other, $next, $preference, $limit)) {
                        continue 2;
                    }
                }
                foreach ($best[$node] ?? [] as $id => $other) {
                    if (self::dominates($next, $other, $preference, $limit)) {
                        unset($best[$node][$id]);
                    }
                }
                $best[$node][$sequence] = $next;
                [$first, $second, $hops] = self::rank($next, $preference);
                $queue->insert($next, [-$first, -$second, -$hops, -$sequence]);
            }
        }
        return null;
    }

    private static function dominates(array $a, array $b, string $preference, ?int $limit): bool
    {
        if ($limit === null || $preference === 'TIME') {
            return self::rank($a, $preference) <= self::rank($b, $preference);
        }

        return $a['cost'] <= $b['cost'] && $a['minutes'] <= $b['minutes']
            && ($a['cost'] < $b['cost'] || $a['minutes'] < $b['minutes'] || $a['hops'] <= $b['hops']);
    }
}
