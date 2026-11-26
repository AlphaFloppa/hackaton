<?php

declare(strict_types=1);

namespace App\Service;

use App\Infrastructure\Database\ConnectionProvider;
use App\Modules\Stock\Application\StockService;
use App\Shared\Api\ConflictException;
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
        private readonly StockService $stock,
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
    public function timeAdvance(int $hours, string $idempotencyKey, bool $accrueStorage): array
    {
        if ($hours < 1) {
            throw new ValidationException("Field 'hours' must be an integer >= 1");
        }

        return $this->changeTime($idempotencyKey, ['hours' => $hours, 'accrue_storage' => $accrueStorage], function () use ($hours, $accrueStorage): array {
            $now = $this->clock->advance($hours);
            if ($accrueStorage) {
                $pvzIds = $this->db->get()->query('SELECT id FROM pvz ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
                foreach ($pvzIds as $pvzId) {
                    $this->stock->accrueStorageAt($pvzId, $now);
                }
            }

            return ['hours_added' => $hours, 'now_override' => $now->format(\DateTimeInterface::ATOM)];
        });
    }

    /** Сброс тестового времени на реальное. */
    public function timeReset(string $idempotencyKey): array
    {
        return $this->changeTime($idempotencyKey, ['reset' => true], function (): array {
            $this->clock->reset();

            return ['now_override' => null];
        });
    }

    private function changeTime(string $idempotencyKey, array $request, callable $change): array
    {
        $key = 'time_request:' . hash('sha256', $idempotencyKey);

        return $this->db->transactional(function (\PDO $pdo) use ($key, $request, $change): array {
            $claim = $pdo->prepare('INSERT INTO time_control (key, value) VALUES (?, NULL) ON CONFLICT (key) DO NOTHING');
            $claim->execute([$key]);
            if ($claim->rowCount() === 0) {
                $stmt = $pdo->prepare('SELECT value FROM time_control WHERE key = ?');
                $stmt->execute([$key]);
                $saved = json_decode($stmt->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
                if ($saved['request'] !== $request) {
                    throw new ConflictException('Idempotency-Key already used for a different time operation');
                }

                return $saved['response'];
            }

            $response = $change();
            $pdo->prepare('UPDATE time_control SET value = ? WHERE key = ?')
                ->execute([json_encode(['request' => $request, 'response' => $response], JSON_THROW_ON_ERROR), $key]);

            return $response;
        });
    }
}
