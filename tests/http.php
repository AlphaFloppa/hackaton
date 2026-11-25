<?php

declare(strict_types=1);

require __DIR__ . '/../demo/fixtures.php';

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8080/api/v1', '/');
function http(string $method, string $path, ?array $payload = null, ?string $key = null): array
{
    global $base;
    $header = ['Accept: application/json'];
    if ($payload !== null) {
        $header[] = 'Content-Type: application/json';
    }
    if ($key !== null) {
        $header[] = 'Idempotency-Key: ' . $key;
    }
    $context = stream_context_create(['http' => [
        'method' => $method, 'header' => implode("\r\n", $header),
        'content' => $payload === null ? '' : json_encode($payload, JSON_THROW_ON_ERROR),
        'ignore_errors' => true, 'timeout' => 5,
    ]]);
    $body = file_get_contents($base . $path, false, $context);
    if ($body === false) {
        throw new RuntimeException('Подключение к демо-серверу не установлено.');
    }
    preg_match('/^HTTP\/\S+ (\d+)/', $http_response_header[0], $matches);
    return [(int) $matches[1], json_decode($body, true, flags: JSON_THROW_ON_ERROR)];
}

function check(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException($name);
    }
    echo "OK  $name\n";
}

[$status, $bootstrap] = http('GET', '/frontend/bootstrap');
check($status === 200 && count($bootstrap['pvz']) === 4 && $bootstrap['orders'] === [], 'Справочники и пустой список заказов');
$pvzIds = array_column($bootstrap['pvz'], 'id');
check($pvzIds === ['00000001', '00000002', '00000003', '00000004'], 'Уникальные восьмисимвольные ID ПВЗ');
[$status, $stock] = http('GET', '/pvz/00000001/stock');
check($status === 200 && $stock['load'] === 30 && $stock['capacity'] === 100
    && $stock['rows'][0]['item'] === 'Куртка' && $stock['rows'][0]['qty'] === 30, 'Остатки по короткому ID');
check(http('GET', '/pvz/' . MSK1 . '/stock') === [200, $stock], 'Старый UUID ПВЗ остаётся рабочим');
check(http('GET', '/pvz/99999999/stock')[0] === 404, 'Неизвестный ПВЗ');

$request = ['item_id' => MUG, 'qty' => 2, 'target_pvz_id' => '00000003', 'preference' => 'COST'];
[$status, $preview] = http('GET', '/frontend/quote?' . http_build_query($request));
check($status === 200 && $preview['chosen'] === 'MESH' && $preview['hub']['ok'], 'Предпросмотр двух схем');

$key = bin2hex(random_bytes(12));
[$status, $order] = http('POST', '/orders', $request, $key);
check($status === 201 && $order['chosen'] === $preview['chosen'] && $order['cost'] === $preview['mesh']['cost']
    && $order['hours'] === $preview['mesh']['hours'] && $order['reason'] === $preview['reason'] && $order['segs'] === $preview['mesh']['segs'],
    'Заказ соответствует предпросмотру');
[$status, $detail] = http('GET', '/orders/' . $order['id']);
check($status === 200 && $detail === $order && $detail['order_calcs']['comparison']['marketplace_saving']['rub'] > 0,
    'GET заказа возвращает маршрут и экономику');
check(http('POST', '/orders', $request, $key) === [201, $order], 'Повтор с тем же ключом');
check(http('POST', '/orders', [...$request, 'target_pvz_id' => MSK3], $key) === [201, $order],
    'Короткий ID и старый UUID обозначают тот же запрос');
check(http('POST', '/orders', [...$request, 'qty' => 3], $key)[0] === 409, 'Один ключ нельзя использовать с другим телом');
check(http('POST', '/orders', $request)[0] === 400, 'Проверка Idempotency-Key');
check(http('GET', '/orders/60000000-0000-4000-8000-000000000009')[0] === 404, 'Неизвестный заказ');

[$status, $error] = http('POST', '/orders', [...$request, 'qty' => 3], bin2hex(random_bytes(12)));
check($status === 409 && $error['quote']['chosen'] === null && str_contains($error['error'], '28/30'),
    'После резерва ёмкости новый заказ сверх лимита отклоняется');
$blockedRequest = [...$request, 'qty' => 3];
[$status, $blocked] = http('GET', '/frontend/quote?' . http_build_query($blockedRequest));
$alternatives = $blocked['alternative_pvzs'];
check($status === 200 && $blocked['chosen'] === null
    && array_column($alternatives, 'id') === ['00000002', '00000001']
    && $alternatives === $error['quote']['alternative_pvzs'],
    'Предпросмотр и отказ при создании предлагают доступные ПВЗ того же города');
foreach ($alternatives as $alternative) {
    [$status, $offer] = http('GET', '/frontend/quote?' . http_build_query([
        ...$blockedRequest, 'target_pvz_id' => $alternative['id'],
    ]));
    $route = $offer['chosen'] === 'MESH' ? $offer['mesh'] : $offer['hub'];
    check($status === 200 && $route['ok'] && $route['cost'] === $alternative['cost']
        && $route['hours'] === $alternative['hours'], 'Предложенный ПВЗ принимает заказ по указанной цене и сроку');
}
[$status, $noOffer] = http('GET', '/frontend/quote?' . http_build_query([...$request, 'qty' => 100]));
check($status === 200 && $noOffer['chosen'] === null && $noOffer['alternative_pvzs'] === [],
    'При отсутствии остатков альтернативы не предлагаются');

$intercity = ['item_id' => JACKET, 'qty' => 10, 'target_pvz_id' => SPB1, 'preference' => 'TIME'];
$intercityKey = bin2hex(random_bytes(12));
[$status, $order] = http('POST', '/orders', $intercity, $intercityKey);
check($status === 201 && $order['chosen'] === 'HUB_TRUNK' && !$order['variants']['mesh']['ok']
    && $order['variants']['mesh']['segs'] === [], 'Межгород и отсутствие этапов недоступного варианта');
[$status, $updated] = http('GET', '/frontend/bootstrap');
check($status === 200 && $updated['orders'][0]['target'] === '00000004'
    && $updated['orders'][0]['source_pvz_id'] === '00000001', 'Короткие ID ПВЗ в списке заказов');
[$status, $afterOrder] = http('GET', '/pvz/00000001/stock');
check($status === 200 && $afterOrder['rows'][0]['qty'] === 30 && $afterOrder['rows'][0]['reserved'] === 10,
    'Остаток показывает физическое количество и резерв');

$timeKey = bin2hex(random_bytes(12));
check(http('POST', '/system/time/advance', ['days' => 1])[0] === 400, 'Для сдвига времени нужен ключ');
check(http('POST', '/system/time/advance', ['days' => -1], $timeKey)[0] === 422, 'Некорректный сдвиг отклоняется');
check(http('POST', '/system/time/advance', ['days' => 1], $key)[0] === 409, 'Повтор ключа другого эндпоинта отклоняется');
[$status, $clock] = http('POST', '/system/time/advance', ['days' => 10], $timeKey);
check($status === 200 && $clock['offset_days'] === 10, 'Сдвиг времени на 10 дней');
check(http('POST', '/system/time/advance', ['days' => 10], $timeKey) === [200, $clock],
    'Повтор запроса не сдвигает время ещё раз');
check(http('POST', '/system/time/advance', ['days' => 1], $timeKey)[0] === 409, 'Повтор ключа с другим сдвигом отклоняется');
[$status, $bootstrap] = http('GET', '/frontend/bootstrap');
check($status === 200 && strtotime($bootstrap['now']) >= strtotime($updated['now']) + 10 * 86400,
    'Системные часы используют сдвиг');
[$status, $afterTime] = http('GET', '/pvz/00000001/stock');
check($status === 200 && $afterTime['rows'][0]['age_days'] >= $afterOrder['rows'][0]['age_days'] + 10
    && $afterTime['rows'][0]['reserved'] === 10, 'Возраст партии вырос, резерв сохранился');

$assemblePath = '/orders/' . $order['id'] . '/assemble';
$assembleKey = bin2hex(random_bytes(12));
$issuePath = '/orders/' . $order['id'] . '/issue';
$issueKey = bin2hex(random_bytes(12));
check(http('POST', $issuePath, [], $issueKey)[0] === 409, 'Созданный заказ нельзя выдать до сборки');
check(http('POST', $assemblePath, [])[0] === 400, 'Для сборки нужен ключ');
check(http('POST', $assemblePath, [], $intercityKey)[0] === 409, 'Ключ создания нельзя использовать для сборки');
check(http('POST', '/orders/60000000-0000-4000-8000-000000000009/assemble', [], $assembleKey)
    === [404, ['error' => 'Заказ не найден.']], 'Сборка неизвестного заказа');
[$status, $assembled] = http('POST', $assemblePath, [], $assembleKey);
check($status === 200 && $assembled['status'] === 'ASSEMBLED' && $assembled['written_off'] === 10,
    'Сборка переводит заказ в ASSEMBLED');
check(http('POST', $assemblePath, [], $assembleKey) === [200, $assembled], 'Повтор сборки с тем же ключом');
[$status, $replay] = http('POST', $assemblePath, [], bin2hex(random_bytes(12)));
check($status === 200 && $replay['replay'] && $replay['written_off'] === 0, 'С новым ключом повторного списания нет');
[$status, $stock] = http('GET', '/pvz/00000001/stock');
check($status === 200 && $stock['load'] === 20 && $stock['rows'][0]['qty'] === 20 && $stock['rows'][0]['reserved'] === 0,
    'После сборки товар списан, резерв снят, место в ПВЗ отправления освобождено');
check(http('GET', '/pvz/00000004/stock')[1]['load'] === 30, 'Место в ПВЗ назначения остаётся зарезервированным');
[$status, $list] = http('GET', '/frontend/bootstrap');
check($status === 200 && $list['orders'][0]['status'] === 'ASSEMBLED'
    && http('GET', '/orders/' . $order['id']) === [200, [...$order, 'status' => 'ASSEMBLED']],
    'Статус сборки виден и в списке, и в карточке');
check(http('POST', '/orders', $intercity, $intercityKey) === [201, $order],
    'Повтор создания возвращает исходный ответ');
check(http('POST', $issuePath, [])[0] === 400 && http('POST', $issuePath, [], $assembleKey)[0] === 409,
    'Выдача требует своего ключа идемпотентности');
check(http('POST', '/orders/60000000-0000-4000-8000-000000000009/issue', [], $issueKey)
    === [404, ['error' => 'Заказ не найден.']], 'Выдача неизвестного заказа');
[$status, $issued] = http('POST', $issuePath, [], $issueKey);
check($status === 200 && $issued['status'] === 'ISSUED' && $issued['issued_qty'] === 10, 'Выдача собранного заказа');
check(http('POST', $issuePath, [], $issueKey) === [200, $issued], 'Повтор выдачи с тем же ключом');
[$status, $replay] = http('POST', $issuePath, [], bin2hex(random_bytes(12)));
check($status === 200 && $replay['replay'] && $replay['issued_qty'] === 0, 'Выдача с новым ключом повторно не проводится');
[$status, $replay] = http('POST', $assemblePath, [], bin2hex(random_bytes(12)));
check($status === 200 && $replay['status'] === 'ISSUED' && $replay['written_off'] === 0,
    'Повтор сборки не меняет статус выданного заказа');
[$status, $sourceAfterIssue] = http('GET', '/pvz/00000001/stock');
check($status === 200 && $sourceAfterIssue === $stock && http('GET', '/pvz/00000004/stock')[1]['load'] === 20,
    'Выдача освобождает место назначения и не списывает товар повторно');
[$status, $list] = http('GET', '/frontend/bootstrap');
check($status === 200 && $list['orders'][0]['status'] === 'ISSUED'
    && http('GET', '/orders/' . $order['id']) === [200, [...$order, 'status' => 'ISSUED']],
    'Выдача видна в списке и в карточке заказа');

[$status, $local] = http('POST', '/orders', [...$request, 'target_pvz_id' => '00000002'], bin2hex(random_bytes(12)));
check($status === 201 && $local['cost'] === 0.0, 'Заказ с выдачей там, где хранится товар');
check(http('POST', '/orders/' . $local['id'] . '/assemble', [], bin2hex(random_bytes(12)))[0] === 200, 'Сборка в ПВЗ выдачи');
$stock = http('GET', '/pvz/00000002/stock')[1];
check($stock['load'] === 15 && $stock['rows'][0]['qty'] === 13 && $stock['rows'][0]['reserved'] === 2,
    'Собранный на месте заказ продолжает занимать место, другие резервы сохраняются');
check(http('POST', '/orders/' . $local['id'] . '/issue', [], bin2hex(random_bytes(12)))[0] === 200,
    'Выдача заказа из остатков самого ПВЗ');
$afterLocalIssue = http('GET', '/pvz/00000002/stock')[1];
check($afterLocalIssue['load'] === 13 && $afterLocalIssue['rows'][0]['qty'] === 13
    && $afterLocalIssue['rows'][0]['reserved'] === 2,
    'Выдача на месте освобождает ёмкость без повторного списания');

$health = http('GET', '/health');
check($health === [200, ['status' => 'ok']], 'Проверка состояния сервера');
check(http('POST', '/items', ['name' => 'Без цены', 'kg' => 1, 'vol' => 0.01])[0] === 422, 'Проверка нового товара');
[$status, $item] = http('POST', '/items', ['name' => 'Книга', 'kg' => 0.4, 'vol' => 0.002, 'price' => 800, 'cogs' => 300]);
check($status === 201 && $item['name'] === 'Книга' && $item['unit_cogs_cents'] === 30000,
    'Создание товара с себестоимостью');
$itemId = $item['id'];
check(http('GET', "/items/$itemId/batches") === [200, []], 'Новый товар без партий');
$intake = ['item_id' => $itemId, 'qty' => 6];
$intakeKey = bin2hex(random_bytes(12));
check(http('POST', '/pvz/00000002/intake', $intake)[0] === 400, 'Приёмка требует ключа');
check(http('POST', '/pvz/00000002/intake', [...$intake, 'qty' => 60], $intakeKey)[0] === 409,
    'Переполнение при приёмке отклоняется');
[$status, $accepted] = http('POST', '/pvz/00000002/intake', $intake, $intakeKey);
check($status === 200 && $accepted['accepted'] === 6 && $accepted['load'] === 19, 'ПВЗ принимает новую партию');
check(http('POST', '/pvz/' . MSK2 . '/intake', $intake, $intakeKey) === [200, $accepted], 'Повтор приёмки со старым ID');
[$status, $batches] = http('GET', "/items/$itemId/batches");
check($status === 200 && count($batches) === 1 && $batches[0]['qty'] === 6 && $batches[0]['pvz_id'] === '00000002',
    'Продавец видит принятую партию');
[$status, $sourceAnalytics] = http('GET', '/analytics/pvz/00000002');
check($status === 200 && $sourceAnalytics['intake'] === 24.0 && $sourceAnalytics['assembly'] === 18.0,
    'Аналитика ПВЗ включает приёмку и сборку');
$storageBefore = $sourceAnalytics['storage'];
$advanceKey = bin2hex(random_bytes(12));
check(http('POST', '/system/time/advance', ['days' => 10], $advanceKey)[0] === 200, 'Демо начисляет ещё один период хранения');
[$status, $sourceAnalytics] = http('GET', '/analytics/pvz/' . MSK2);
check($status === 200 && $sourceAnalytics['storage'] >= $storageBefore + 285.0, 'Начисление хранения по физическому остатку');
$storedCharge = $sourceAnalytics['storage'];
http('POST', '/system/time/advance', ['days' => 10], $advanceKey);
check(http('GET', '/analytics/pvz/00000002')[1]['storage'] === $storedCharge, 'Повтор сдвига не начисляет хранение дважды');
[$status, $notes] = http('GET', '/notifications?pvz=00000002');
check($status === 200 && count(array_filter($notes, static fn (array $n): bool =>
    $n['pvz'] === '00000002' && $n['type'] === 'LONG_STORAGE' && str_contains($n['text'], 'Книга'))) === 1,
    'ПВЗ видит одно уведомление о долгом хранении новой партии');

$newRequest = ['item_id' => $itemId, 'qty' => 2, 'target_pvz_id' => '00000003', 'preference' => 'COST'];
$newKey = bin2hex(random_bytes(12));
[$status, $new] = http('POST', '/orders', $newRequest, $newKey);
check($status === 201 && $new['chosen'] === 'MESH', 'Принятая партия участвует в маршрутизации');
$newOrder = '/orders/' . $new['id'];
$incident = ['order_id' => $new['id'], 'type' => 'WRONG_PVZ'];
$incidentKey = bin2hex(random_bytes(12));
[$status, $registered] = http('POST', '/incidents', $incident, $incidentKey);
check($status === 200 && $registered['from'] === 'MESH' && $registered['extra_cost'] > 0 && $registered['extra_hours'] === 6.0,
    'Инцидент пересчитывает маршрут и регистрирует последствия');
[$status, $revised] = http('GET', $newOrder);
check($status === 200 && $revised['cost'] > $new['cost'] && $revised['hours'] > $new['hours']
    && $revised['base'] === $new['base'] && $revised['segs'] !== $new['segs'] && count($revised['incidents']) === 1,
    'Карточка показывает обновлённый путь, цену и срок, база сохраняется');
check(http('POST', '/orders', $newRequest, $newKey) === [201, $new], 'Повтор создания сохраняет первоначальный ответ после инцидента');
check(http('POST', '/incidents', $incident, $incidentKey) === [200, $registered]
    && http('POST', '/incidents', $incident, bin2hex(random_bytes(12)))[0] === 409,
    'Повтор инцидента не добавляет вторую задержку');
[$status, $notes] = http('GET', '/notifications?pvz=00000003');
check($status === 200 && count(array_filter($notes, static fn (array $n): bool =>
    $n['type'] === 'ERROR' && str_contains($n['text'], substr($new['id'], 0, 8)))) === 1,
    'ПВЗ назначения получает уведомление об инциденте');
check(http('POST', "$newOrder/return", [], bin2hex(random_bytes(12)))[0] === 409, 'Возврат до выдачи отклоняется');
check(http('POST', "$newOrder/assemble", [], bin2hex(random_bytes(12)))[0] === 200
    && http('POST', "$newOrder/issue", [], bin2hex(random_bytes(12)))[0] === 200,
    'Заказ после пересчёта можно собрать и выдать');

[$status, $seller] = http('GET', '/analytics/seller/' . SELLER);
check($status === 200 && $seller['issued'] === 3 && $seller['revenue'] === 44500.0
    && $seller['cogs'] === 25000.0 && array_sum($seller['schemes']) === $seller['orders'],
    'Аналитика продавца считает продажи, себестоимость и способы доставки');
$capacityRequest = ['item_id' => $itemId, 'qty' => 1];
check(http('POST', '/pvz/00000003/intake', $capacityRequest, bin2hex(random_bytes(12)))[0] === 200,
    'Доступное место можно занять новой партией');
$returnKey = bin2hex(random_bytes(12));
check(http('POST', "$newOrder/return", [], $returnKey)[0] === 409, 'Возврат в переполненный ПВЗ отклоняется');
[$status, $localBook] = http('POST', '/orders', [...$newRequest, 'qty' => 1], bin2hex(random_bytes(12)));
$localPath = '/orders/' . $localBook['id'];
check($status === 201 && $localBook['cost'] === 0.0
    && http('POST', "$localPath/assemble", [], bin2hex(random_bytes(12)))[0] === 200
    && http('POST', "$localPath/issue", [], bin2hex(random_bytes(12)))[0] === 200,
    'Выдача освобождает место для повторной попытки возврата');
[$status, $returned] = http('POST', "$newOrder/return", [], $returnKey);
check($status === 200 && $returned['status'] === 'RETURNED' && $returned['restocked'] === 2, 'Возврат формирует новую партию');
check(http('POST', "$newOrder/return", [], $returnKey) === [200, $returned]
    && http('POST', "$newOrder/return", [], bin2hex(random_bytes(12)))[1]['restocked'] === 0,
    'Повторы возврата не дублируют партию');
[$status, $stock] = http('GET', '/pvz/00000003/stock');
$returnedUnits = array_sum(array_column(array_filter($stock['rows'], static fn (array $row): bool => $row['item'] === 'Книга'), 'qty'));
check($status === 200 && $stock['load'] === 30 && $returnedUnits === 2, 'После возврата ёмкость и количество согласованы');
[$status, $targetAnalytics] = http('GET', '/analytics/pvz/00000003');
check($status === 200 && $targetAnalytics['corrections'] === -12.0 && $targetAnalytics['issued'] === 1 && $targetAnalytics['returned'] === 1,
    'Аналитика ПВЗ учитывает возврат и сторно выдачи');
[$status, $seller] = http('GET', '/analytics/seller/' . SELLER);
check($status === 200 && $seller['returned'] === 1 && $seller['revenue'] === 43700.0, 'Возврат исключён из выручки продавца');
check(http('GET', $newOrder)[1]['status'] === 'RETURNED' && count(http('GET', $newOrder)[1]['incidents']) === 1,
    'История маршрута остаётся в карточке после возврата');

[$status, $hub] = http('POST', '/stub/hub/handle', ['chargeable_kg' => 1], bin2hex(random_bytes(12)));
check($status === 200 && $hub['is_stub'] && $hub['hub_fee']['total'] === 40.0, 'Заглушка обработки хаба');
[$status, $trunk] = http('POST', '/stub/trunk/move', ['chargeable_kg' => 2, 'km' => 100], bin2hex(random_bytes(12)));
check($status === 200 && $trunk['is_stub'] && $trunk['cost'] === 24.0, 'Заглушка магистрали использует тариф модели');
