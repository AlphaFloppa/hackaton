<?php

declare(strict_types=1);

use Mesh\Routing\OrderResponse;
use Mesh\Routing\Router;

final class DemoRequestError extends RuntimeException
{
}

function pvzFees(array $model, array $pvz): array
{
    return array_replace($model['tariffs']['pvz'], $pvz['fees'] ?? []);
}

function demoInteger(mixed $value, int $min, int $max, string $field): int
{
    if (!is_int($value) || $value < $min || $value > $max) {
        throw new InvalidArgumentException("Поле $field должно быть целым числом от $min до $max.");
    }
    return $value;
}

function demoNumber(mixed $value, float $min, float $max, string $field): float
{
    if ((!is_int($value) && !is_float($value)) || !is_finite($value) || $value < $min || $value > $max) {
        throw new InvalidArgumentException("Проверьте числовое поле $field.");
    }
    return (float) $value;
}

function demoOrder(array $stored): array
{
    return [...($stored['latest_response'] ?? $stored['response']), 'status' => $stored['row']['status']];
}

/** Use inside withState(true). Existing order creation keys keep their original shape. */
function demoOnce(array &$state, string $key, string $signature, callable $operation): array
{
    if (isset($state['keys'][$key])) {
        $existing = $state['keys'][$key];
        if ($existing['signature'] !== $signature) {
            throw new DemoRequestError('Idempotency-Key уже использован для другого запроса.', 409);
        }
        return $existing['body'];
    }
    $body = $operation($state);
    $state['keys'][$key] = ['signature' => $signature, 'body' => $body];
    return $body;
}

function receiveBatch(array &$state, string $pvzId, string $itemId, int $qty, string $kind): array
{
    $network = demoNetwork($state);
    $pvz = $network['pvzs'][$pvzId] ?? throw new DemoRequestError('ПВЗ не найден.', 404);
    if (!isset($network['items'][$itemId])) {
        throw new DemoRequestError('Товар не найден.', 404);
    }
    if (!$pvz['is_active'] || $pvz['used_units'] + $qty > $pvz['capacity_units']) {
        throw new DemoRequestError('ПВЗ недоступен или не хватает места для партии.', 409);
    }

    $batch = [
        'batch_id' => uuid(), 'item_id' => $itemId, 'kind' => 'PVZ', 'location_id' => $pvzId,
        'available_qty' => $qty, 'initial_qty' => $qty, 'received_at' => gmdate(DATE_ATOM, demoTimestamp($state)), 'receipt' => $kind,
    ];
    $state['batches'][] = $batch;
    return [
        'batch_id' => $batch['batch_id'], 'accepted' => $qty,
        'load' => $pvz['used_units'] + $qty, 'capacity' => $pvz['capacity_units'],
    ];
}

function batchRows(array $network, int $now, ?string $pvzId = null, ?string $itemId = null): array
{
    $rows = [];
    foreach ($network['stock'] as $batch) {
        $point = $batch['location_id'];
        $item = $batch['item_id'];
        if ($batch['kind'] !== 'PVZ' || ($pvzId !== null && $point !== $pvzId)
            || ($itemId !== null && ($item !== $itemId || $batch['qty'] === 0))) {
            continue;
        }
        $rows[] = [
            'item' => $network['items'][$item]['name'], 'pvz' => $network['pvzs'][$point]['name'],
            'pvz_id' => publicPvzId($point), 'batch_id' => $batch['batch_id'],
            'qty' => $batch['qty'], 'reserved' => $batch['reserved'],
            'age_days' => intdiv(max(0, $now - strtotime($batch['received_at'])), SECONDS_PER_DAY),
        ];
    }
    return $rows;
}

/** Calculate storage through complete days at the quantity before a stock movement. */
function storageAccruals(array $state, array $network, array $model, int $now): array
{
    $accruals = $state['storage'] ?? [];
    foreach ($network['stock'] as $batch) {
        $id = $batch['batch_id'];
        $entry = $accruals[$id] ?? ['through' => strtotime($batch['received_at']), 'cents' => 0];
        $days = intdiv(max(0, $now - $entry['through']), SECONDS_PER_DAY);
        if ($days > 0) {
            $rate = pvzFees($model, $network['pvzs'][$batch['location_id']])['storage_unit_day_cents'];
            $accruals[$id] = [
                'through' => $entry['through'] + $days * SECONDS_PER_DAY,
                'cents' => $entry['cents'] + $rate * $days * $batch['qty'],
            ];
        }
    }
    return $accruals;
}

function notifyPvz(array &$state, string $type, string $pvz, string $text): void
{
    $state['notifications'][] = ['type' => $type, 'pvz' => $pvz, 'text' => $text, 't' => demoTimestamp($state) * 1000];
}

function demoNotifications(array $state, ?string $pvzId): array
{
    $network = demoNetwork($state);
    $now = demoTimestamp($state);
    $notes = $state['notifications'] ?? [];
    foreach ($network['stock'] as $batch) {
        $warning = strtotime($batch['received_at']) + 7 * SECONDS_PER_DAY;
        if ($batch['qty'] > 0 && $now >= $warning) {
            $item = $network['items'][$batch['item_id']]['name'];
            $notes[] = [
                'type' => 'LONG_STORAGE', 'pvz' => $batch['location_id'], 't' => $warning * 1000,
                'text' => "Партия «{$item}» хранится 7 суток или дольше.",
            ];
        }
    }
    if ($pvzId !== null) {
        $notes = array_values(array_filter($notes, static fn (array $note): bool => $note['pvz'] === $pvzId));
    }
    usort($notes, static fn (array $a, array $b): int => $b['t'] <=> $a['t']);
    return array_map(static fn (array $note): array => [...$note, 'pvz' => publicPvzId($note['pvz'])], $notes);
}

function reportDemoIncident(array &$state, Router $router, array $model, string $orderId, string $type): array
{
    $stored = &$state['orders'][$orderId];
    if (!isset($stored)) {
        throw new DemoRequestError('Заказ не найден.', 404);
    }
    $order = demoOrder($stored);
    if (!in_array($order['status'], ['CREATED', 'ASSEMBLED'], true)) {
        throw new DemoRequestError('Логистический инцидент можно отметить до выдачи заказа.', 409);
    }
    if ($order['incidents'] !== []) {
        throw new DemoRequestError('Инцидент по заказу уже зарегистрирован.', 409);
    }
    $row = $stored['row'];
    $snapshot = snapshotFor(demoNetwork($state), $row['item']);
    $request = $stored['request'] ?? [
        'item_id' => $row['item'], 'target_pvz_id' => $row['target'], 'qty' => $row['qty'],
        'storage_days' => $order['order_calcs']['storage_days'] ?? $model['assumptions']['default_storage_days'],
    ];
    $terms = $model['assumptions']['demo_incident'];
    $extraCents = $terms['fixed_cents'] + (int) round($order['cost'] * 100 * $terms['share_bps'] / 10000);
    $delay = $terms['delay_minutes'];
    // В демо сообщение об ошибке поступает до отправления груза из исходного ПВЗ.
    $progress = [
        'kind' => 'PVZ', 'location_id' => $row['source_pvz_id'],
        'own_target_reservation' => $row['qty'],
    ];

    $edge = $stored['first_edge'] ?? null;
    if ($edge === null) {
        $before = $router->plan($request, $snapshot, $progress);
        $key = $order['chosen'] === 'MESH' ? 'mesh' : 'hub';
        $edge = $before['paths'][$key]['path'][0]['id'] ?? null;
    }
    $progress['spent_cents'] = $extraCents;
    $progress['elapsed_minutes'] = $delay;
    $reroute = $router->plan($request, [...$snapshot, 'blocked_edges' => $edge === null ? [] : [$edge]], $progress);
    $detail = $edge === null ? 'Расчёт обновлён с учётом задержки.' : 'Путь пересчитан без проблемного плеча.';
    if ($reroute['selected'] === null && $edge !== null) {
        $reroute = $router->plan($request, $snapshot, $progress);
        $detail = 'Обход недоступен. Доставка продолжится после восстановления плеча.';
    }
    if ($reroute['selected'] !== null) {
        $next = OrderResponse::afterIncident($order, $order['status'], $reroute, $type, $extraCents, $delay);
    } else {
        // Резерв сохраняется до восстановления единственного доступного пути.
        $next = [...$order, 'cost' => round($order['cost'] + Router::rubles($extraCents), 2),
            'hours' => round($order['hours'] + Router::hours($delay), 4)];
        $next['incidents'][] = [
            'type' => $type, 'from' => $order['chosen'], 'to' => $order['chosen'],
            'extra_cost' => Router::rubles($extraCents), 'extra_hours' => Router::hours($delay),
        ];
        $detail = 'Обход недоступен. Ожидаем восстановления маршрута. Срок увеличен.';
    }
    $next['reason'] = 'Логистическая ошибка. ' . $detail . ($reroute['selected'] === null ? '' : ' ' . $next['reason']);
    $index = array_key_last($next['incidents']);
    $incident = ['id' => uuid(), 'order' => $orderId, ...$next['incidents'][$index]];
    $next['incidents'][$index] = $incident;
    $stored['latest_response'] = $next;
    $stored['row'] = [...$row, ...$next];
    $text = 'Ошибка по заказу ' . substr($orderId, 0, 8) . '. ' . $detail;
    foreach (array_unique([$row['source_pvz_id'], $row['target']]) as $point) {
        notifyPvz($state, 'ERROR', $point, $text);
    }
    return $incident;
}

function pvzAnalytics(array $state, array $model, string $id): array
{
    $network = demoNetwork($state);
    $pvz = $network['pvzs'][$id] ?? throw new DemoRequestError('ПВЗ не найден.', 404);
    $fees = pvzFees($model, $pvz);
    $storage = storageAccruals($state, $network, $model, demoTimestamp($state));
    $money = ['intake' => 0, 'assembly' => 0, 'issue' => 0, 'storage' => 0, 'corrections' => 0];
    $counts = ['issued' => 0, 'returned' => 0];
    foreach ($network['stock'] as $batch) {
        if ($batch['location_id'] === $id) {
            if (($batch['receipt'] ?? '') === 'INTAKE') {
                $money['intake'] += $batch['initial_qty'] * $fees['intake_unit_cents'];
            }
            $money['storage'] += $storage[$batch['batch_id']]['cents'] ?? 0;
        }
    }
    foreach ($state['orders'] as $stored) {
        $row = $stored['row'];
        if ($row['source_pvz_id'] === $id && $row['status'] !== 'CREATED') {
            $money['assembly'] += $fees['assembly_order_cents'];
        }
        if ($row['target'] === $id && in_array($row['status'], ['ISSUED', 'RETURNED'], true)) {
            $money['issue'] += $fees['issue_order_cents'];
            if ($row['status'] === 'RETURNED') {
                $money['corrections'] -= $fees['issue_order_cents'];
                $counts['returned']++;
            } else {
                $counts['issued']++;
            }
        }
    }
    $income = array_sum($money);
    $cost = (int) round($income * $model['assumptions']['pvz_operation_cost_bps'] / 10000);
    return [
        ...array_map(Router::rubles(...), $money), ...$counts,
        'income' => Router::rubles($income), 'cost' => Router::rubles($cost), 'profit' => Router::rubles($income - $cost),
        'load' => $pvz['used_units'], 'capacity' => $pvz['capacity_units'],
    ];
}

function sellerAnalytics(array $state, array $model): array
{
    $network = demoNetwork($state);
    $revenue = $cogs = $commission = $shipping = $storage = $base = $selected = $comparable = $missingCogs = 0;
    $counts = ['issued' => 0, 'returned' => 0, 'schemes' => ['MESH' => 0, 'HUB_TRUNK' => 0]];
    foreach ($state['orders'] as $stored) {
        $row = $stored['row'];
        $order = demoOrder($stored);
        $counts['schemes'][$order['chosen']]++;
        if ($order['base'] !== null) {
            $base += (int) round($order['base'] * 100);
            $selected += (int) round($order['cost'] * 100);
            $comparable++;
        }
        if (!in_array($row['status'], ['ISSUED', 'RETURNED'], true)) {
            continue;
        }
        $terms = $model['tariffs'];
        $days = $stored['response']['order_calcs']['storage_days'] ?? $model['assumptions']['default_storage_days'];
        $shipping += $terms['seller_shipping_cents'];
        $storage += $terms['seller_storage_unit_day_cents'] * $row['qty'] * $days;
        if ($row['status'] === 'RETURNED') {
            $counts['returned']++;
            continue;
        }
        $counts['issued']++;
        $item = $network['items'][$row['item']];
        $sale = $item['unit_price_cents'] * $row['qty'];
        $revenue += $sale;
        $commission += (int) round($sale * $terms['commission_bps'] / 10000);
        $cogs += ($item['unit_cogs_cents'] ?? 0) * $row['qty'];
        $missingCogs += isset($item['unit_cogs_cents']) ? 0 : 1;
    }
    return [
        ...$counts, 'orders' => count($state['orders']), 'comparable_orders' => $comparable,
        'revenue' => Router::rubles($revenue), 'cogs' => $missingCogs ? null : Router::rubles($cogs),
        'commission' => Router::rubles($commission), 'logistics' => Router::rubles($shipping),
        'storage' => Router::rubles($storage), 'unknown_cogs_orders' => $missingCogs,
        'profit' => $missingCogs ? null : Router::rubles($revenue - $cogs - $commission - $shipping - $storage),
        'base_cost' => Router::rubles($base), 'mesh_cost' => Router::rubles($selected),
        'saving' => Router::rubles($base - $selected), 'saving_pct' => $base ? round(100 * ($base - $selected) / $base, 2) : null,
    ];
}
