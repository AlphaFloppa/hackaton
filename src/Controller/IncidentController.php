<?php

declare(strict_types=1);

namespace App\Controller;

use App\Modules\Notification\Application\NotificationService;
use App\Modules\Notification\Infrastructure\IncidentRepository;
use App\Shared\Api\ApiController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class IncidentController extends ApiController
{
    public function __construct(
        private readonly IncidentRepository $incidents,
        private readonly NotificationService $notifications,
    ) {}

    /** Нормализует UUID-поля: пустая строка/не-строка -> NULL, иначе lowercase. */
    private static function optUuid(array $body, string $field): ?string
    {
        $v = $body[$field] ?? null;
        if (!is_string($v) || !preg_match('/^[0-9a-f-]{36}$/i', $v)) {
            return null;
        }

        return strtolower($v);
    }


    /** Фиксация инцидента: POST /incidents. */
    public function create(Request $request): Response
    {
        return self::handleExceptions(function () use ($request): Response {
            $key = self::requireIdempotencyKey($request);
            $body = self::body($request);

            $orderId = self::optUuid($body, 'order_id');
            $pvzId = self::optUuid($body, 'pvz_id');
            $type = trim((string) ($body['type'] ?? ''));
            $description = trim((string) ($body['description'] ?? ''));
            if ($type === '' || $description === '') {
                throw new \App\Shared\Api\ValidationException("Fields 'type' and 'description' are required");
            }

            $id = $this->incidents->add($orderId, $pvzId, $type, $description);
            $this->notifications->add($pvzId, $orderId, 'INCIDENT', "Инцидент [$type]: $description");

            return $this->json(['id' => $id, 'type' => $type], 201);
        });
    }
}
