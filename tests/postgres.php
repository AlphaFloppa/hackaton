<?php

declare(strict_types=1);

use Mesh\Routing\DecisionStore;
use Mesh\Routing\OrderResponse;
use Mesh\Routing\Router;

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../demo/fixtures.php';

$dsn = getenv('MESH_TEST_DSN');
if ($dsn === false || $dsn === '') {
    fwrite(STDERR, "Укажите MESH_TEST_DSN для отдельной тестовой БД с применённой миграцией.\n");
    exit(1);
}

$db = new PDO($dsn, getenv('MESH_TEST_USER') ?: null, getenv('MESH_TEST_PASSWORD') ?: null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$store = new DecisionStore($db);
$router = new Router(require __DIR__ . '/../config/model.php');
$snapshot = snapshotFor(fixture(), MUG);
$request = ['item_id' => MUG, 'target_pvz_id' => MSK3, 'qty' => 2];
$plan = $router->plan($request, $snapshot);
$id = sprintf('60000000-0000-4000-8000-%s', bin2hex(random_bytes(6)));
$created = OrderResponse::create($id, 'CREATED', $plan);

function ensure(bool $value, string $message): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
    echo "OK  $message\n";
}

$db->beginTransaction();
try {
    $decision = $store->append($id, 1, $created, $plan);
    $check = $db->prepare('SELECT
        (SELECT count(*) FROM mesh_routing.graph_nodes WHERE decision_id = ?) AS nodes,
        (SELECT count(*) FROM mesh_routing.graph_edges WHERE decision_id = ?) AS edges,
        (SELECT model_version FROM mesh_routing.order_calcs WHERE decision_id = ?) AS model
    ');
    $check->execute([$decision, $decision, $decision]);
    $row = $check->fetch(PDO::FETCH_ASSOC);
    ensure((int) $row['nodes'] === count($plan['graph']['nodes'])
        && (int) $row['edges'] === count($plan['graph']['edges'])
        && $row['model'] === $plan['model']['version'], 'Граф и экономика сохранены');
    $fresh = $store->latest($id, 'ISSUED');
    ensure($fresh['revision'] === 1 && $fresh['response']['cost'] === $created['cost']
        && $fresh['response']['status'] === 'ISSUED'
        && $fresh['response']['order_calcs'] == $plan['order_calcs'], 'Статус приходит из доменного заказа');

    $snapshot['blocked_edges'] = [
        'MESH|pvz:' . MSK2 . '|pvz:' . MSK1,
        'MESH|pvz:' . MSK2 . '|pvz:' . MSK3,
    ];
    $reroute = $router->plan($request, $snapshot,
        ['kind' => 'PVZ', 'location_id' => MSK2, 'spent_cents' => 500, 'elapsed_minutes' => 60]);
    $next = OrderResponse::afterIncident($created, 'ASSEMBLED', $reroute, 'BLOCKED', 500, 60);
    $store->append($id, 2, $next, $reroute);
    $fresh = $store->latest($id, 'ASSEMBLED');
    ensure($fresh['revision'] === 2 && $fresh['response']['chosen'] === 'HUB_TRUNK'
        && $fresh['response']['base'] === $created['base'] && count($fresh['response']['incidents']) === 1, 'Новая ревизия после сбоя');
} finally {
    $db->rollBack();
}

ensure($store->latest($id, 'CREATED') === null, 'Транзакционный откат');
$db->beginTransaction();
try {
    $store->append($id, 2, $next, $reroute);
    throw new RuntimeException('Пропуск ревизии был разрешён.');
} catch (PDOException $error) {
    ensure($error->getCode() === '23503', 'Пропуск ревизии отклонён PostgreSQL');
} finally {
    $db->rollBack();
}
