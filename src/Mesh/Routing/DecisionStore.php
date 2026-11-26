<?php

declare(strict_types=1);

namespace Mesh\Routing;

use LogicException;
use PDO;

final class DecisionStore
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Вызывать внутри транзакции, уже удерживающей блокировку заказа.
     * Для ошибок PDO в приложении включить ERRMODE_EXCEPTION.
     */
    public function append(string $orderId, int $revision, array $response, array $plan): int
    {
        if (!$this->db->inTransaction() || $this->db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new LogicException('Запись маршрута требует транзакции PDO и ERRMODE_EXCEPTION.');
        }
        if ($plan['selected'] === null || $response['id'] !== $orderId || $response['chosen'] !== $plan['quote']['chosen']) {
            throw new LogicException('Несогласованные данные решения и заказа.');
        }

        $decision = $this->db->prepare('
            INSERT INTO mesh_routing.routing_decisions
              (order_id, revision, chosen, preference, reason, response, selected_path, model_snapshot)
            VALUES (?, ?, ?, ?, ?, CAST(? AS jsonb), CAST(? AS jsonb), CAST(? AS jsonb))
            RETURNING id
        ');
        // Статус всегда читается из доменного заказа.
        $route = $response;
        unset($route['status'], $route['order_calcs']);
        $decision->execute([$orderId, $revision, $response['chosen'], $plan['request']['preference'], $response['reason'],
            self::json($route), self::json($plan['selected']), self::json($plan['model'])]);
        $decisionId = (int) $decision->fetchColumn();

        $node = $this->db->prepare('
            INSERT INTO mesh_routing.graph_nodes (decision_id, node_key, kind, name, city_id)
            VALUES (?, ?, ?, ?, ?)
        ');
        foreach ($plan['graph']['nodes'] as $row) {
            $node->execute([$decisionId, $row['id'], $row['kind'], $row['name'], $row['city']]);
        }

        $edge = $this->db->prepare('
            INSERT INTO mesh_routing.graph_edges (decision_id, edge_key, from_node, to_node, kind, cost_cents, minutes, label)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        foreach ($plan['graph']['edges'] as $row) {
            $edge->execute([$decisionId, $row['id'], $row['from'], $row['to'], $row['kind'], $row['cost'], $row['minutes'], $row['label']]);
        }

        $calc = $this->db->prepare('
            INSERT INTO mesh_routing.order_calcs (decision_id, model_version, forecast)
            VALUES (?, ?, CAST(? AS jsonb))
        ');
        $calc->execute([$decisionId, $plan['model']['version'], self::json($plan['order_calcs'])]);
        return $decisionId;
    }

    public function latest(string $orderId, string $domainStatus): ?array
    {
        $query = $this->db->prepare('
            SELECT decision.revision, decision.response, calc.forecast
            FROM mesh_routing.routing_decisions AS decision
            JOIN mesh_routing.order_calcs AS calc ON calc.decision_id = decision.id
            WHERE decision.order_id = ?
            ORDER BY decision.revision DESC
            LIMIT 1
        ');
        $query->execute([$orderId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $response = json_decode($row['response'], true, flags: JSON_THROW_ON_ERROR);
        $forecast = json_decode($row['forecast'], true, flags: JSON_THROW_ON_ERROR);
        return [
            'revision' => (int) $row['revision'],
            'response' => [...$response, 'status' => $domainStatus, 'order_calcs' => $forecast],
        ];
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
