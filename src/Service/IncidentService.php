<?php

declare(strict_types=1);

namespace App\Service;

use App\Modules\Notification\Application\NotificationService;
use App\Modules\Notification\Infrastructure\IncidentRepository;
use App\Shared\Api\ValidationException;

/**
 * Сервис для IncidentController: фиксация логистических ошибок.
 */
final class IncidentService
{
    public function __construct(
        private readonly IncidentRepository $incidents,
        private readonly NotificationService $notifications,
    ) {}

    /** Создание инцидента + уведомление ПВЗ. @param array<string,mixed> $body */
    public function create(array $body): array
    {
        $orderId = $this->optUuid($body, 'order_id');
        $pvzId = $this->optUuid($body, 'pvz_id');
        $type = trim((string) ($body['type'] ?? ''));
        $description = trim((string) ($body['description'] ?? ''));
        if ($type === '' || $description === '') {
            throw new ValidationException("Fields 'type' and 'description' are required");
        }

        $id = $this->incidents->add($orderId, $pvzId, $type, $description);
        $this->notifications->add($pvzId, $orderId, 'INCIDENT', "Инцидент [$type]: $description");

        return ['id' => $id, 'type' => $type];
    }

    /** Нормализует UUID-поля: пустая строка/не-строка -> NULL, иначе lowercase. @param array<string,mixed> $body */
    private function optUuid(array $body, string $field): ?string
    {
        $v = $body[$field] ?? null;
        if (!is_string($v) || !preg_match('/^[0-9a-f-]{36}$/i', $v)) {
            return null;
        }

        return strtolower($v);
    }
}
