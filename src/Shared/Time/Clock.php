<?php

declare(strict_types=1);

namespace App\Shared\Time;

use App\Infrastructure\Database\ConnectionProvider;

/**
 * Тестовое время: сценарий "длительное хранение" двигает часы через /system/time/advance.
 */
final class Clock
{
    public function __construct(
        private readonly ConnectionProvider $db,
    ) {}

    public function now(): \DateTimeImmutable
    {
        $row = $this->db->get()
            ->query("SELECT value FROM time_control WHERE key = 'now_override'")
            ->fetch(\PDO::FETCH_ASSOC);

        if ($row !== false && $row['value'] !== null && $row['value'] !== '') {
            return new \DateTimeImmutable($row['value']);
        }

        return new \DateTimeImmutable('now');
    }

    public function advance(int $hours): \DateTimeImmutable
    {
        $pdo = $this->db->get();
        $pdo->beginTransaction();
        try {
            $pdo->exec("INSERT INTO time_control (key, value) VALUES ('now_override', now()::text)
                        ON CONFLICT (key) DO NOTHING");
            $pdo->prepare("UPDATE time_control
                           SET value = (COALESCE(NULLIF(value,'')::timestamptz, now()) + make_interval(hours => ?))::text
                           WHERE key = 'now_override'")
                ->execute([$hours]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $this->now();
    }
}
