<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure;

use App\Infrastructure\Database\ConnectionProvider;

final class IncidentRepository
{
    public function __construct(
        private readonly ConnectionProvider $db,
    ) {}

    /** Добавление проводки/записи. */
    public function add(?string $orderId, ?string $pvzId, string $type, string $description): string
    {
        $id = $this->uuid();

        $this->db->get()
            ->prepare('INSERT INTO incidents (id, order_id, pvz_id, type, description) VALUES (?, ?, ?, ?, ?)')
            ->execute([$id, $orderId, $pvzId, $type, $description]);

        return $id;
    }

    /** UUID v4 из random_bytes. */
    public function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
