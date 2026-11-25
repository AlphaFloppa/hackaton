<?php

declare(strict_types=1);

use Mesh\Routing\Graph;
use Mesh\Routing\NoRoute;
use Mesh\Routing\OrderResponse;
use Mesh\Routing\Router;

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../demo/fixtures.php';

function requireThat(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function test(string $name, callable $body): void
{
    try {
        $body();
        echo "OK  $name\n";
    } catch (Throwable $error) {
        fwrite(STDERR, "FAIL  $name: $error\n");
        exit(1);
    }
}

$model = require __DIR__ . '/../config/model.php';
$router = new Router($model);
$mugs = snapshotFor(fixture(), MUG);
$jacket = snapshotFor(fixture(), JACKET);
$orderId = '60000000-0000-4000-8000-000000000001';
$request = ['item_id' => MUG, 'target_pvz_id' => MSK3, 'qty' => 2, 'preference' => 'COST'];
$city = $router->plan($request, $mugs);

test('Городской заказ, формат API, вес и цена по формуле', static function () use ($city, $orderId): void {
    $order = OrderResponse::create($orderId, 'CREATED', $city);
    $quote = $city['quote'];
    requireThat($quote['ch'] === 1.0, 'Объёмный вес не применён');
    requireThat($order['chosen'] === 'MESH' && $order['cost'] === 24.21 && $order['hours'] === 3.2, 'Некорректный mesh');
    requireThat($order['base'] === 113.0 && $order['variants']['hub']['ok'], 'Некорректная база');
    requireThat($order['id'] === $orderId && $order['status'] === 'CREATED' && $order['incidents'] === [], 'Поля заказа');
    requireThat($order['segs'] === $order['variants']['mesh']['segs'] && end($order['segs'])['e'] === true, 'Сегменты');
    requireThat(str_contains($order['reason'], '88,79'), 'Числовое обоснование');
    requireThat(count(array_filter($order['variants']['hub']['segs'], static fn (array $s): bool => $s['is_stub'] ?? false)) === 2,
        'Пометки заглушки');
    requireThat(!in_array('TRUNK', array_column($city['paths']['hub']['path'], 'kind'), true), 'В городе магистраль отсутствует');
});

test('Межгород с двумя хабами и скрытым недоступным mesh', static function () use ($router, $jacket): void {
    $plan = $router->plan(['item_id' => JACKET, 'qty' => 10, 'target_pvz_id' => SPB1, 'preference' => 'TIME'], $jacket);
    $quote = $plan['quote'];
    requireThat($quote['chosen'] === 'HUB_TRUNK' && $quote['sla'] === 72, 'Выбор межгорода');
    requireThat($quote['mesh']['ok'] === false && $quote['mesh']['segs'] === [] && $quote['mesh']['cost'] === null, 'Недоступность');
    $kinds = array_column($plan['selected']['path'], 'kind');
    requireThat($kinds === ['FIRST_MILE', 'HANDLING', 'TRUNK', 'HANDLING', 'LAST_MILE'], 'Состав маршрута');
    requireThat($quote['ch'] === 75.0 && $plan['order_calcs']['base']['transfer_by_leg_cents']['HANDLING'] === 8000, 'Магистраль и хабы');
});

test('COST и TIME выбирают разные схемы на одном наборе данных', static function () use ($router, $request, $mugs): void {
    $mugs['edge_overrides'] = [
        'MESH|pvz:' . MSK2 . '|pvz:' . MSK3 => ['minutes' => 1000],
        'MESH|pvz:' . MSK2 . '|pvz:' . MSK1 => ['minutes' => 1000],
    ];
    $cost = $router->plan($request, $mugs)['quote'];
    $time = $router->plan([...$request, 'preference' => 'TIME'], $mugs)['quote'];
    requireThat($cost['chosen'] === 'MESH' && $time['chosen'] === 'HUB_TRUNK', 'Приоритет должен менять выбор');
});

test('Дейкстра учитывает SLA и сохраняет более быстрый префикс', static function (): void {
    $g = new Graph();
    foreach (['S', 'A', 'B', 'T'] as $id) {
        $g->addNode($id, $id, 'PVZ', 'CITY');
    }
    $g->addEdge('S', 'A', 'MESH', 1, 8, '');
    $g->addEdge('S', 'B', 'MESH', 4, 2, '');
    $g->addEdge('B', 'A', 'MESH', 1, 2, '');
    $g->addEdge('A', 'T', 'MESH', 1, 4, '');
    $g->addEdge('S', 'T', 'MESH', 10, 3, '');
    requireThat($g->shortest('S', 'T', ['MESH'], 'COST', 9)['cost'] === 6, 'Потерян допустимый префикс');
    requireThat($g->shortest('S', 'T', ['MESH'], 'COST', null)['cost'] === 2, 'Пропал дешёвый путь');
    requireThat($g->shortest('S', 'T', ['MESH'], 'TIME', 9)['minutes'] === 3, 'Пропал быстрый путь');
});

test('Маршрут вне SLA, расчёт базы и обоснование', static function () use ($router, $request, $mugs): void {
    $mugs['pvzs'][MSK3]['sla_hours_city'] = 4;
    $plan = $router->plan($request, $mugs);
    requireThat($plan['quote']['chosen'] === 'MESH' && !$plan['quote']['hub']['ok'], 'Хаб выходит за SLA');
    requireThat($plan['quote']['base'] === 113.0 && !$plan['order_calcs']['base']['eligible'], 'База для сравнения');
    requireThat(str_contains($plan['quote']['hub']['why'], 'SLA 4'), 'Причина');
});

test('Ёмкость, закрытый ПВЗ и отсутствие остатка блокируют заказ', static function () use ($router, $request, $mugs, $orderId): void {
    $full = $mugs;
    $full['pvzs'][MSK3]['used_units'] = 29;
    $closed = $mugs;
    $closed['pvzs'][MSK3]['is_active'] = false;
    $noStock = $mugs;
    $noStock['stock'] = [];
    foreach ([[$full, '29/30'], [$closed, 'недоступен'], [$noStock, 'остатка']] as [$snapshot, $fragment]) {
        $plan = $router->plan($request, $snapshot);
        requireThat($plan['quote']['chosen'] === null && !$plan['quote']['mesh']['ok'] && !$plan['quote']['hub']['ok'], 'Обе схемы заблокированы');
        try {
            OrderResponse::create($orderId, 'CREATED', $plan);
            throw new RuntimeException('Создан заказ без маршрута.');
        } catch (NoRoute $error) {
            requireThat(str_contains($error->getMessage(), $fragment), 'Причина блокировки');
        }
    }
});

test('Выдача товара из остатков целевого ПВЗ без нового места', static function () use ($router, $request, $mugs): void {
    $mugs['pvzs'][MSK2]['used_units'] = 60;
    $quote = $router->plan([...$request, 'target_pvz_id' => MSK2], $mugs)['quote'];
    requireThat($quote['mesh']['ok'] && $quote['mesh']['cost'] === 0.0 && $quote['mesh']['hours'] === 0.0, 'Товар уже в точке');
    requireThat(count($quote['mesh']['segs']) === 1, 'Лишнее плечо');
});

test('Несколько партий в точке и выбор источника по полному маршруту', static function () use ($router, $request, $mugs): void {
    $mugs['stock'] = [
        [...$mugs['stock'][0], 'batch_id' => 'near', 'available_qty' => 2],
        [...$mugs['stock'][0], 'batch_id' => 'old', 'location_id' => MSK1, 'received_at' => '2026-11-20', 'available_qty' => 1],
        [...$mugs['stock'][0], 'batch_id' => 'new', 'location_id' => MSK1, 'received_at' => '2026-11-21', 'available_qty' => 1],
    ];
    $mugs['edge_overrides'] = ['MESH|pvz:' . MSK1 . '|pvz:' . MSK3 => ['cost_cents' => 100]];
    $plan = $router->plan($request, $mugs);
    requireThat($plan['selected']['source']['location_id'] === MSK1, 'Выбран ближайший ПВЗ вместо более дешёвого пути');
    requireThat($plan['selected']['source']['allocations'] === [['batch_id' => 'old', 'qty' => 1], ['batch_id' => 'new', 'qty' => 1]], 'Резервы по партиям');
});

test('Источник у продавца, отключение хаба и отсутствующая база', static function () use ($router, $request, $mugs): void {
    $sellerStock = $mugs;
    $sellerStock['stock'][0]['kind'] = 'SELLER';
    $sellerStock['stock'][0]['location_id'] = SELLER;
    $plan = $router->plan($request, $sellerStock);
    requireThat($plan['quote']['chosen'] === 'HUB_TRUNK' && str_starts_with($plan['selected']['path'][0]['from'], 'seller:'), 'Ребро продавец → хаб');
    $mugs['hubs'][HUB_MSK]['is_active'] = false;
    $quote = $router->plan($request, $mugs)['quote'];
    requireThat($quote['chosen'] === 'MESH' && $quote['base'] === null && $quote['hub']['segs'] === [], 'База отсутствует');
});

test('Риск, оплата по ролям и граница выгодности по хранению', static function () use ($router, $request, $mugs, $city): void {
    $calc = $city['order_calcs'];
    $mesh = $calc['mesh'];
    requireThat($mesh['pvz']['income_cents'] === 4100 && $mesh['pvz']['result_cents'] === 2460, 'Деньги ПВЗ');
    requireThat($mesh['expected_return_cents'] + $mesh['expected_incident_cents'] === 240, 'Ожидаемые риски');
    requireThat($mesh['marketplace']['cost_cents'] === 6761 && $mesh['marketplace']['margin_cents'] === 13099, 'Расходы и выручка маркетплейса');
    requireThat($mesh['seller']['profit_cents'] === 30140 && $mesh['buyer']['delivery_cents'] === 0, 'Продавец и покупатель');
    requireThat($calc['comparison']['transfer_saving'] === ['rub' => 88.79, 'pct' => 78.58], 'Абсолют и процент');
    requireThat($calc['comparison']['buyer_delivery_saving']['pct'] === null, 'Нулевая база');
    $day55 = $router->plan([...$request, 'storage_days' => 55], $mugs)['order_calcs'];
    $day56 = $router->plan([...$request, 'storage_days' => 56], $mugs)['order_calcs'];
    requireThat($calc['pilot_conditions']['marketplace_no_cost_increase']
        && $day55['comparison']['marketplace_saving']['rub'] === 1.49
        && $day56['comparison']['marketplace_saving']['rub'] === -0.11
        && !$day56['pilot_conditions']['marketplace_no_cost_increase'], 'Граница выгодности при долгом хранении');
    unset($mugs['item']['unit_cogs_cents'], $mugs['item']['unit_price_cents']);
    $missing = $router->plan($request, $mugs)['order_calcs']['mesh'];
    requireThat($missing['seller']['profit_cents'] === null && $missing['marketplace']['margin_cents'] === null, 'Неизвестные цены нельзя подменять нулём');
});

test('Инцидент, резерв места, новые верхнеуровневые поля и исходная база', static function () use ($router, $request, $mugs, $city, $orderId): void {
    $previous = OrderResponse::create($orderId, 'CREATED', $city);
    $mugs['blocked_edges'] = ['MESH|pvz:' . MSK2 . '|pvz:' . MSK3, 'MESH|pvz:' . MSK2 . '|pvz:' . MSK1];
    $mugs['pvzs'][MSK3]['used_units'] = 30;
    $mugs['stock'] = [];
    $plan = $router->plan($request, $mugs, [
        'kind' => 'PVZ', 'location_id' => MSK2, 'own_target_reservation' => 2,
        'spent_cents' => 710, 'elapsed_minutes' => 17,
        'completed_segs' => [['n' => 'ПВЗ Москва-2', 'l' => 'Сборка выполнена']],
    ]);
    $next = OrderResponse::afterIncident($previous, 'ASSEMBLED', $plan, 'EDGE_UNAVAILABLE', 710, 17);
    requireThat($next['chosen'] === 'HUB_TRUNK' && $next['cost'] === 120.1 && $next['base'] === 113.0, 'Пересчитанная стоимость');
    requireThat($next['hours'] === $plan['quote']['hub']['hours'] && $next['hours'] > $previous['hours'], 'Обновлённый срок');
    requireThat($next['segs'][0]['l'] === 'Сборка выполнена' && $next['segs'] === $next['variants']['hub']['segs'], 'Фактические этапы');
    requireThat($next['incidents'][0]['from'] === 'MESH' && $next['incidents'][0]['extra_cost'] === 7.1, 'Инцидент');
    requireThat($plan['selected']['source']['allocations'] === [], 'Нельзя резервировать остаток вторично');
});

test('Некорректные входы', static function () use ($router, $request, $mugs): void {
    foreach ([['qty' => 0], ['qty' => 1.5], ['qty' => []], ['preference' => 'UNKNOWN'], ['target_pvz_id' => 'missing']] as $bad) {
        try {
            $router->plan([...$request, ...$bad], $mugs);
            throw new RuntimeException('Некорректный запрос принят.');
        } catch (InvalidArgumentException) {
        }
    }
});
