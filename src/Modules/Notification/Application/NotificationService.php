<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application;

use App\Modules\Notification\Infrastructure\NotificationRepository;

final class NotificationService
{
    public function __construct(
        private readonly NotificationRepository $repo,
    ) {}

    /** Добавление проводки/записи. */
    public function add(?string $pvzId, ?string $orderId, string $type, string $message): void
    {
        $this->repo->add($pvzId, $orderId, $type, $message);
    }

    public function addOnce(string $pvzId, string $type, string $message): void
    {
        $this->repo->addOnce($pvzId, $type, $message);
    }

    /** Последние уведомления (по ПВЗ или все). */
    public function forPvz(?string $pvzId): array
    {
        return $this->repo->forPvz($pvzId);
    }

    /** Пометить уведомления прочитанными; возвращает число обновлённых. */
    public function markAllRead(?string $pvzId): int
    {
        return $this->repo->markAllRead($pvzId);
    }
}
