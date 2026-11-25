<?php

declare(strict_types=1);

use Mesh\Routing\NoRoute;
use Mesh\Routing\OrderResponse;
use Mesh\Routing\Router;

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/fixtures.php';
require __DIR__ . '/operations.php';

const DEMO_PVZ_IDS = [
    '00000001' => MSK1,
    '00000002' => MSK2,
    '00000003' => MSK3,
    '00000004' => SPB1,
];
const SECONDS_PER_DAY = 86400;

function response(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
    $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

/** Тестовое хранилище для ручного прогона HTTP. */
function withState(bool $write, callable $operation): mixed
{
    $directory = getenv('MESH_DEMO_STATE') ?: __DIR__ . '/.state';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Не удалось создать каталог демо.');
    }
    $path = $directory . '/orders.json';
    $lock = fopen($directory . '/orders.lock', 'c');
    if ($lock === false) {
        throw new RuntimeException('Не удалось открыть блокировку.');
    }
    try {
        if (!flock($lock, $write ? LOCK_EX : LOCK_SH)) {
            throw new RuntimeException('Не удалось заблокировать состояние демо.');
        }
        $state = is_file($path)
            ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)
            : ['orders' => [], 'keys' => []];
        $result = $operation($state);
        if ($write) {
            $temporary = tempnam($directory, 'orders-');
            if ($temporary === false) {
                throw new RuntimeException('Не удалось подготовить сохранение состояния.');
            }
            try {
                $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
                if (file_put_contents($temporary, $json) !== strlen($json) || !rename($temporary, $path)) {
                    throw new RuntimeException('Не удалось сохранить состояние демо.');
                }
            } finally {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
        }
        return $result;
    } finally {
        fclose($lock);
    }
}

function demoNetwork(array $state): array
{
    $fixture = fixture();
    $fixture['items'] += $state['items'] ?? [];
    foreach ($state['batches'] ?? [] as $batch) {
        $fixture['stock'][] = $batch;
        $fixture['pvzs'][$batch['location_id']]['used_units'] += $batch['available_qty'];
    }
    $reserved = [];
    $dispatched = [];
    foreach ($state['orders'] as $order) {
        $row = $order['row'];
        $leftNetwork = in_array($row['status'], ['ISSUED', 'RETURNED'], true);
        $packed = $row['status'] === 'ASSEMBLED' || $leftNetwork;
        $source = $row['source_pvz_id'];
        $target = $row['target'];
        foreach ($order['allocations'] as $allocation) {
            $batch = $allocation['batch_id'];
            if ($packed) {
                $dispatched[$batch] = ($dispatched[$batch] ?? 0) + $allocation['qty'];
            } else {
                $reserved[$batch] = ($reserved[$batch] ?? 0) + $allocation['qty'];
            }
        }
        if ($source !== $target && !$leftNetwork) {
            $fixture['pvzs'][$target]['used_units'] += $row['qty'];
        }
        if ($packed && $source !== null && ($source !== $target || $leftNetwork)) {
            $fixture['pvzs'][$source]['used_units'] -= $row['qty'];
        }
    }
    foreach ($fixture['stock'] as &$batch) {
        $batch['qty'] = $batch['available_qty'] - ($dispatched[$batch['batch_id']] ?? 0);
        $batch['reserved'] = $reserved[$batch['batch_id']] ?? 0;
        $batch['available_qty'] = $batch['qty'] - $batch['reserved'];
    }
    unset($batch);
    return $fixture;
}

/** Выполняется под блокировкой состояния вместе с записью ключа запроса. */
function updateOrderStatus(array &$state, array $model, string $id, string $action, string $key): array
{
    $signature = hash('sha256', "POST /api/v1/orders/$id/$action");
    if (isset($state['keys'][$key])) {
        $existing = $state['keys'][$key];
        return $existing['signature'] === $signature
            ? [200, $existing['body']]
            : [409, ['error' => 'Idempotency-Key уже использован для другого запроса.']];
    }
    if (!isset($state['orders'][$id])) {
        return [404, ['error' => 'Заказ не найден.']];
    }

    $order = &$state['orders'][$id];
    $status = $order['row']['status'];
    [$from, $to, $verb, $quantityField] = match ($action) {
        'assemble' => ['CREATED', 'ASSEMBLED', 'собрать', 'written_off'],
        'issue' => ['ASSEMBLED', 'ISSUED', 'выдать', 'issued_qty'],
        'return' => ['ISSUED', 'RETURNED', 'вернуть', 'restocked'],
    };
    $alreadyProcessed = $status === $to || ($action === 'assemble' && in_array($status, ['ISSUED', 'RETURNED'], true))
        || ($action === 'issue' && $status === 'RETURNED');
    if ($status !== $from && !$alreadyProcessed) {
        return [409, ['error' => "Нельзя $verb заказ в статусе $status."]];
    }
    $replay = $status !== $from;
    if (!$replay) {
        if ($action !== 'issue') {
            $state['storage'] = storageAccruals($state, demoNetwork($state), $model, demoTimestamp($state));
        }
        if ($action === 'return') {
            $row = $order['row'];
            receiveBatch($state, $row['target'], $row['item'], $row['qty'], 'RETURN');
            notifyPvz($state, 'INFO', $row['target'],
                'Возврат по заказу ' . substr($id, 0, 8) . ': ' . $row['qty'] . ' ед. снова на остатке.');
        }
        $order['row']['status'] = $to;
    }
    $body = [
        'order_id' => $id, 'status' => $order['row']['status'],
        $quantityField => $replay ? 0 : $order['row']['qty'], 'replay' => $replay,
    ];
    $state['keys'][$key] = ['signature' => $signature, 'body' => $body];
    return [200, $body];
}

function publicPvzId(?string $id): ?string
{
    return array_search($id, DEMO_PVZ_IDS, true) ?: $id;
}

function normalizeTarget(array $input): array
{
    $target = $input['target_pvz_id'] ?? null;
    if (is_string($target) && isset(DEMO_PVZ_IDS[$target])) {
        $input['target_pvz_id'] = DEMO_PVZ_IDS[$target];
    }
    return $input;
}

function suggestPickupPoints(Router $router, array $request, array $snapshot, array $quote): array
{
    $quote['alternative_pvzs'] = [];
    if ($quote['chosen'] !== null) {
        return $quote;
    }

    $target = $request['target_pvz_id'];
    $city = $snapshot['pvzs'][$target]['city_id'];
    foreach ($snapshot['pvzs'] as $id => $pvz) {
        if ($id === $target || $pvz['city_id'] !== $city || !$pvz['is_active']) {
            continue;
        }
        $alternative = $router->plan([...$request, 'target_pvz_id' => $id], $snapshot)['quote'];
        if ($alternative['chosen'] === null) {
            continue;
        }
        $route = $alternative[$alternative['chosen'] === 'MESH' ? 'mesh' : 'hub'];
        $quote['alternative_pvzs'][] = [
            'id' => publicPvzId($id), 'name' => $pvz['name'],
            'cost' => $route['cost'], 'hours' => $route['hours'],
        ];
    }

    usort($quote['alternative_pvzs'], static function (array $a, array $b) use ($request): int {
        return $request['preference'] === 'TIME'
            ? [$a['hours'], $a['cost'], $a['id']] <=> [$b['hours'], $b['cost'], $b['id']]
            : [$a['cost'], $a['hours'], $a['id']] <=> [$b['cost'], $b['hours'], $b['id']];
    });
    return $quote;
}

function demoTimestamp(array $state): int
{
    return time() + ($state['offset_days'] ?? 0) * SECONDS_PER_DAY;
}

function idempotencyKey(): string
{
    $key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';
    if ($key === '' || strlen($key) > 128) {
        response(400, ['error' => 'Укажите Idempotency-Key длиной до 128 символов.']);
    }
    return $key;
}

function jsonInput(): array
{
    $input = json_decode(file_get_contents('php://input'), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($input)) {
        throw new InvalidArgumentException('Передайте JSON с параметрами запроса.');
    }
    return $input;
}

function postDemoAction(string $path, array $input, callable $operation): never
{
    $key = idempotencyKey();
    $signature = hash('sha256', $path . ' ' . json_encode($input, JSON_THROW_ON_ERROR));
    $body = withState(true, static fn (array &$state): array => demoOnce($state, $key, $signature, $operation));
    response(200, $body);
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Idempotency-Key');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
$model = require __DIR__ . '/../config/model.php';
$router = new Router($model);

try {
    if ($method === 'GET' && $path === '/') {
        header('Location: /demo?api=/api/v1', true, 302);
        exit;
    }
    if ($method === 'GET' && $path === '/demo') {
        header('Content-Type: text/html; charset=utf-8');
        readfile(__DIR__ . '/frontend.html');
        exit;
    }
    if ($method === 'GET' && $path === '/api/v1/health') {
        response(200, ['status' => 'ok']);
    }
    if ($method === 'POST' && $path === '/api/v1/items') {
        $input = jsonInput();
        $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
        if ($name === '' || strlen($name) > 240) {
            throw new InvalidArgumentException('Укажите название товара до 240 байт.');
        }
        $item = [
            'id' => uuid(), 'name' => $name,
            'kg' => demoNumber($input['kg'] ?? null, 0.000001, 100000, 'kg'),
            'volume_m3' => demoNumber($input['vol'] ?? null, 0.000001, 1000, 'vol'),
            'unit_price_cents' => (int) round(demoNumber($input['price'] ?? null, 0, 10000000, 'price') * 100),
            'unit_cogs_cents' => isset($input['cogs'])
                ? (int) round(demoNumber($input['cogs'], 0, 10000000, 'cogs') * 100) : null,
        ];
        withState(true, static function (array &$state) use ($item): void {
            $state['items'][$item['id']] = $item;
        });
        response(201, $item);
    }
    if ($method === 'GET' && preg_match('~^/api/v1/items/([^/]+)/batches$~D', $path, $match)) {
        $id = $match[1];
        $rows = withState(false, static function (array $state) use ($id): array {
            $network = demoNetwork($state);
            if (!isset($network['items'][$id])) {
                throw new DemoRequestError('Товар не найден.', 404);
            }
            return batchRows($network, demoTimestamp($state), itemId: $id);
        });
        response(200, $rows);
    }
    if ($method === 'POST' && preg_match('~^/api/v1/pvz/([^/]+)/intake$~D', $path, $match)) {
        $id = DEMO_PVZ_IDS[$match[1]] ?? $match[1];
        $input = jsonInput();
        $item = $input['item_id'] ?? null;
        if (!is_string($item) || $item === '') {
            throw new InvalidArgumentException('Укажите товар.');
        }
        $qty = demoInteger($input['qty'] ?? null, 1, 1000000, 'qty');
        postDemoAction("/api/v1/pvz/$id/intake", [$item, $qty],
            static function (array &$state) use ($id, $item, $qty): array {
                return receiveBatch($state, $id, $item, $qty, 'INTAKE');
            });
    }
    if ($method === 'POST' && $path === '/api/v1/incidents') {
        $input = jsonInput();
        $id = $input['order_id'] ?? null;
        $type = $input['type'] ?? 'WRONG_PVZ';
        if (!is_string($id) || !is_string($type) || preg_match('/^[A-Z][A-Z0-9_]{0,49}$/D', $type) !== 1) {
            throw new InvalidArgumentException('Укажите заказ и тип инцидента.');
        }
        postDemoAction($path, [$id, $type], static fn (array &$state): array => reportDemoIncident($state, $router, $model, $id, $type));
    }
    if ($method === 'GET' && preg_match('~^/api/v1/analytics/(pvz|seller)/([^/]+)$~D', $path, $match)) {
        $id = $match[2];
        if ($match[1] === 'seller' && $id !== SELLER) {
            throw new DemoRequestError('Продавец не найден.', 404);
        }
        $analytics = withState(false, static fn (array $state): array =>
            $match[1] === 'seller' ? sellerAnalytics($state, $model) : pvzAnalytics($state, $model, DEMO_PVZ_IDS[$id] ?? $id));
        response(200, $analytics);
    }
    if ($method === 'GET' && $path === '/api/v1/notifications') {
        $id = $_GET['pvz'] ?? null;
        if ($id !== null && !is_string($id)) {
            throw new InvalidArgumentException('Укажите идентификатор ПВЗ.');
        }
        $id = DEMO_PVZ_IDS[$id] ?? $id;
        if ($id !== null && !isset(fixture()['pvzs'][$id])) {
            throw new DemoRequestError('ПВЗ не найден.', 404);
        }
        response(200, withState(false, static fn (array $state): array => demoNotifications($state, $id)));
    }
    if ($method === 'POST' && ($path === '/api/v1/stub/hub/handle' || $path === '/api/v1/stub/trunk/move')) {
        $input = jsonInput();
        $kg = demoNumber($input['chargeable_kg'] ?? null, 0, 100000000000, 'chargeable_kg');
        if ($path === '/api/v1/stub/hub/handle') {
            $fee = $model['tariffs']['hub'];
            $body = [
                'is_stub' => true, 'chargeable_kg' => $kg, 'hours' => Router::hours($fee['minutes']),
                'hub_fee' => [
                    'handling_in' => Router::rubles($fee['handling_in_cents']), 'sorting' => Router::rubles($fee['sorting_cents']),
                    'handling_out' => Router::rubles($fee['handling_out_cents']),
                    'total' => Router::rubles($fee['handling_in_cents'] + $fee['sorting_cents'] + $fee['handling_out_cents']),
                ],
            ];
            postDemoAction($path, [$kg], static fn (array &$state): array => $body);
        }
        $km = demoNumber($input['km'] ?? null, 0, 25000, 'km');
        $trunk = $model['tariffs']['trunk'];
        $body = [
            'is_stub' => true, 'cost' => Router::rubles((int) round($kg * $km * $trunk['per_kg_km_cents'])),
            'hours' => Router::hours($trunk['wait_min'] + (int) ceil($km / $trunk['kmph'] * 60)),
            'formula' => 'chargeable_kg × km × ' . ($trunk['per_kg_km_cents'] / 100),
        ];
        postDemoAction($path, [$kg, $km], static fn (array &$state): array => $body);
    }
    if ($method === 'GET' && $path === '/api/v1/frontend/quote') {
        $quote = withState(false, static function (array $state) use ($router): array {
            $item = $_GET['item_id'] ?? '';
            if (!is_string($item)) {
                throw new InvalidArgumentException('Некорректный item_id.');
            }
            $snapshot = snapshotFor(demoNetwork($state), $item);
            $plan = $router->plan(normalizeTarget($_GET), $snapshot);
            return suggestPickupPoints($router, $plan['request'], $snapshot, $plan['quote']);
        });
        response(200, $quote);
    }
    if ($method === 'GET' && $path === '/api/v1/frontend/bootstrap') {
        $bootstrap = withState(false, static function (array $state) use ($model): array {
            $fixture = demoNetwork($state);
            $pvzs = [];
            foreach ($fixture['pvzs'] as $id => $pvz) {
                $fees = pvzFees($model, $pvz);
                $pvzs[] = [
                    ...$pvz, 'id' => publicPvzId($id), 'city' => $pvz['city_id'],
                    'cap' => $pvz['capacity_units'], 'active' => $pvz['is_active'],
                    'fi' => Router::rubles($fees['intake_unit_cents']),
                    'fa' => Router::rubles($fees['assembly_order_cents']),
                    'fis' => Router::rubles($fees['issue_order_cents']),
                    'fs' => Router::rubles($fees['storage_unit_day_cents']),
                ];
            }
            $orders = [];
            foreach (array_reverse($state['orders']) as $order) {
                $row = $order['row'];
                $row['target'] = publicPvzId($row['target']);
                $row['source_pvz_id'] = publicPvzId($row['source_pvz_id']);
                $orders[] = $row;
            }
            return ['seller' => $fixture['sellers'][SELLER], 'items' => array_values($fixture['items']), 'pvz' => $pvzs,
                'orders' => $orders, 'now' => gmdate(DATE_ATOM, demoTimestamp($state))];
        });
        response(200, $bootstrap);
    }
    if ($method === 'GET' && preg_match('~^/api/v1/pvz/([^/]+)/stock$~D', $path, $match)) {
        $id = DEMO_PVZ_IDS[$match[1]] ?? $match[1];
        $stock = withState(false, static function (array $state) use ($id): ?array {
            $fixture = demoNetwork($state);
            $pvz = $fixture['pvzs'][$id] ?? null;
            if ($pvz === null) {
                return null;
            }
            $rows = batchRows($fixture, demoTimestamp($state), $id);
            return ['load' => $pvz['used_units'], 'capacity' => $pvz['capacity_units'], 'rows' => $rows];
        });
        response($stock === null ? 404 : 200, $stock ?? ['error' => 'ПВЗ не найден.']);
    }
    if ($method === 'POST' && $path === '/api/v1/system/time/advance') {
        $key = idempotencyKey();
        $input = json_decode(file_get_contents('php://input'), true, flags: JSON_THROW_ON_ERROR);
        $days = $input['days'] ?? null;
        if (!is_int($days) || $days < 1 || $days > 3650) {
            throw new InvalidArgumentException('Укажите целое число дней от 1 до 3650.');
        }
        $signature = hash('sha256', "$method $path $days");
        [$code, $body] = withState(true, static function (array &$state) use ($key, $days, $signature, $model): array {
            if (isset($state['keys'][$key])) {
                $existing = $state['keys'][$key];
                return $existing['signature'] === $signature
                    ? [200, $existing['body']]
                    : [409, ['error' => 'Idempotency-Key уже использован для другого запроса.']];
            }
            $state['offset_days'] = ($state['offset_days'] ?? 0) + $days;
            $state['storage'] = storageAccruals($state, demoNetwork($state), $model, demoTimestamp($state));
            $body = ['now' => gmdate(DATE_ATOM, demoTimestamp($state)), 'offset_days' => $state['offset_days']];
            $state['keys'][$key] = ['signature' => $signature, 'body' => $body];
            return [200, $body];
        });
        response($code, $body);
    }
    if ($method === 'POST' && $path === '/api/v1/orders') {
        $key = idempotencyKey();
        $input = json_decode(file_get_contents('php://input'), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($input) || !is_string($input['item_id'] ?? null)) {
            throw new InvalidArgumentException('Передайте JSON заказа с item_id.');
        }
        $input = normalizeTarget($input);

        [$code, $body] = withState(true, static function (array &$state) use ($router, $input, $key): array {
            ksort($input);
            $signature = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
            if (isset($state['keys'][$key])) {
                $existing = $state['keys'][$key];
                return $existing['signature'] === $signature
                    ? [201, $state['orders'][$existing['id']]['response']]
                    : [409, ['error' => 'Idempotency-Key уже использован для другого запроса.']];
            }
            $snapshot = snapshotFor(demoNetwork($state), $input['item_id']);
            $plan = $router->plan($input, $snapshot);
            if ($plan['selected'] === null) {
                throw new NoRoute(suggestPickupPoints($router, $plan['request'], $snapshot, $plan['quote']));
            }
            $id = uuid();
            $response = OrderResponse::create($id, 'CREATED', $plan);
            $source = $plan['selected']['source'];
            $row = [
                ...$response,
                'item' => $plan['request']['item_id'],
                'qty' => $plan['request']['qty'],
                'target' => $plan['request']['target_pvz_id'],
                'source_pvz_id' => $source['kind'] === 'PVZ' ? $source['location_id'] : null,
            ];
            $state['orders'][$id] = [
                'response' => $response, 'row' => $row, 'allocations' => $source['allocations'],
                'request' => $plan['request'], 'first_edge' => $plan['selected']['path'][0]['id'] ?? null,
            ];
            $state['keys'][$key] = ['signature' => $signature, 'id' => $id];
            return [201, $response];
        });
        response($code, $body);
    }
    if ($method === 'POST' && preg_match('~^/api/v1/orders/([0-9a-f-]{36})/(assemble|issue|return)$~D', $path, $match)) {
        $key = idempotencyKey();
        [$code, $body] = withState(true, static fn (array &$state): array => updateOrderStatus($state, $model, $match[1], $match[2], $key));
        response($code, $body);
    }
    if ($method === 'GET' && preg_match('~^/api/v1/orders/([0-9a-f-]{36})$~D', $path, $match)) {
        $order = withState(false, static function (array $state) use ($match): ?array {
            $stored = $state['orders'][$match[1]] ?? null;
            return $stored === null ? null : demoOrder($stored);
        });
        response($order === null ? 404 : 200, $order ?? ['error' => 'Заказ не найден.']);
    }
    response(404, ['error' => 'Эндпоинт демо не найден.']);
} catch (NoRoute $error) {
    response(409, ['error' => $error->getMessage(), 'quote' => $error->quote]);
} catch (DemoRequestError $error) {
    response($error->getCode(), ['error' => $error->getMessage()]);
} catch (JsonException | InvalidArgumentException $error) {
    response(422, ['error' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log((string) $error);
    response(500, ['error' => 'Ошибка демо-сервера.']);
}
