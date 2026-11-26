<?php

declare(strict_types=1);

namespace App\Shared\Time;

use App\Infrastructure\Database\ConnectionProvider;
use App\Shared\Api\ValidationException;

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
        return $this->override() ?? new \DateTimeImmutable();
    }

    public function status(): array
    {
        $override = $this->override();

        return [
            'now' => ($override ?? new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'now_override' => $override?->format(\DateTimeInterface::ATOM),
        ];
    }

    /** Блокировка часов удерживается до конца транзакции системной операции. */
    public function advance(int $hours): \DateTimeImmutable
    {
        $pdo = $this->db->get();
        $pdo->exec("INSERT INTO time_control (key, value) VALUES ('now_override', NULL) ON CONFLICT (key) DO NOTHING");
        $current = ($this->override(lock: true) ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone('UTC'));
        $last = new \DateTimeImmutable('9999-12-31T23:59:59Z');
        if ($hours < 1 || $hours > intdiv($last->getTimestamp() - $current->getTimestamp(), 3600)) {
            throw new ValidationException('Сдвиг должен быть положительным и оставлять дату в пределах 9999 года');
        }

        $next = $current->add(new \DateInterval("PT{$hours}H"));
        $pdo->prepare("UPDATE time_control SET value = ? WHERE key = 'now_override'")
            ->execute([$next->format('Y-m-d\TH:i:s.uP')]);

        return $next;
    }

    public function reset(): void
    {
        $this->db->get()->exec("INSERT INTO time_control (key, value) VALUES ('now_override', NULL)
                               ON CONFLICT (key) DO UPDATE SET value = NULL");
    }

    public static function wholeDaysSince(string $since, \DateTimeImmutable $now): int
    {
        $start = new \DateTimeImmutable($since);
        $seconds = $now->getTimestamp() - $start->getTimestamp();
        if ($now->format('u') < $start->format('u')) {
            --$seconds;
        }

        return max(0, intdiv($seconds, 86400));
    }

    private function override(bool $lock = false): ?\DateTimeImmutable
    {
        $value = $this->db->get()
            ->query("SELECT value FROM time_control WHERE key = 'now_override'" . ($lock ? ' FOR UPDATE' : ''))
            ->fetchColumn();

        return is_string($value) && $value !== '' ? new \DateTimeImmutable($value) : null;
    }
}
