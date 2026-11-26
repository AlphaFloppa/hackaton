<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure;

use App\Infrastructure\Database\ConnectionProvider;

final class NotificationRepository
{
    public function __construct(
        private readonly ConnectionProvider $db,
    ) {}

    /** Добавление проводки/записи. */
    public function add(?string $pvzId, ?string $orderId, string $type, string $message): void
    {
        $this->db->get()
            ->prepare('INSERT INTO notifications (pvz_id, order_id, type, message) VALUES (?, ?, ?, ?)')
            ->execute([$pvzId, $orderId, $type, $message]);
    }

    /** Для вызовов в транзакции, удерживающей блокировку ПВЗ. */
    public function addOnce(string $pvzId, string $type, string $message): void
    {
        $this->db->get()
            ->prepare(
                'INSERT INTO notifications (pvz_id, type, message)
                 SELECT ?, ?, ? WHERE NOT EXISTS (
                     SELECT 1 FROM notifications WHERE pvz_id = ? AND type = ? AND message = ?
                 )'
            )
            ->execute([$pvzId, $type, $message, $pvzId, $type, $message]);
    }

    /** @return array<int,array<string,mixed>> */
    public function forPvz(?string $pvzId, int $limit = 50): array
    {
        if ($pvzId === null) {
            $stmt = $this->db->get()->prepare(
                'SELECT id, pvz_id, order_id, type, message, is_read, created_at
                 FROM notifications ORDER BY created_at DESC LIMIT ?'
            );
            $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $stmt = $this->db->get()->prepare(
                'SELECT id, pvz_id, order_id, type, message, is_read, created_at
                 FROM notifications WHERE pvz_id = ? ORDER BY created_at DESC LIMIT ?'
            );
            $stmt->bindValue(1, $pvzId);
            $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
            $stmt->execute();
        }

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Пометить уведомления прочитанными; возвращает число обновлённых. */
    public function markAllRead(?string $pvzId): int
    {
        $stmt = $pvzId === null
            ? $this->db->get()->prepare('UPDATE notifications SET is_read = true WHERE NOT is_read')
            : $this->db->get()->prepare('UPDATE notifications SET is_read = true WHERE NOT is_read AND pvz_id = ?');
        $stmt->execute($pvzId === null ? [] : [$pvzId]);

        return $stmt->rowCount();
    }
}
