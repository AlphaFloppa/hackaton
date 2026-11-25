<?php

declare(strict_types=1);

namespace App\Service;

use App\Infrastructure\Database\ConnectionProvider;
use App\Shared\Api\ValidationException;
use App\Shared\Time\Clock;

/**
 * Сервис для SystemController: healthcheck и управление тестовым временем.
 */
final class SystemService
{
    public function __construct(
        private readonly ConnectionProvider $db,
        private readonly Clock $clock,
    ) {}

    /** Healthcheck: доступность БД. */
    public function health(): array
    {
        try {
            $ok = $this->db->get()->query('SELECT 1')->fetchColumn() !== false;
        } catch (\Throwable) {
            $ok = false;
        }

        return ['status' => $ok ? 'ok' : 'db_down', 'service' => 'wildb-backend'];
    }

    /** Сдвиг тестового времени на N часов. */
    public function timeAdvance(int $hours): array
    {
        if ($hours < 1) {
            throw new ValidationException("Field 'hours' must be an integer >= 1");
        }

        $now = $this->clock->advance($hours);

        return ['hours_added' => $hours, 'now_override' => $now->format(\DateTimeInterface::ATOM)];
    }

    /** Сброс тестового времени на реальное. */
    public function timeReset(): array
    {
        $this->db->get()->prepare("DELETE FROM time_control WHERE key = 'now_override'")->execute();

        return ['now_override' => null];
    }
}
