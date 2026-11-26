<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure;

use App\Infrastructure\Database\ConnectionProvider;
use App\Shared\Api\NotFoundException;

final class CatalogRepository
{
    public function __construct(
        private readonly ConnectionProvider $db,
    ) {}

    /** Создание карточки товара. */
    public function createItem(string $sellerId, string $sku, string $title, string $weightKg, string $volumeM3): string
    {
        $id = $this->newUuid();
        $stmt = $this->db->get()->prepare(
            'INSERT INTO items (id, seller_id, sku, title, weight_kg, volume_m3)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$id, $sellerId, $sku, $title, $weightKg, $volumeM3]);

        return $id;
    }

    /** @return array<string,mixed>|null */
    public function findItem(string $itemId): ?array
    {
        $stmt = $this->db->get()->prepare('SELECT * FROM items WHERE id = ?');
        $stmt->execute([$itemId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** Остатки товара по ПВЗ. @return array<int,array<string,mixed>> */
    public function itemBatches(string $itemId): array
    {
        $stmt = $this->db->get()->prepare(
            'SELECT b.id AS batch_id, b.pvz_id, p.name AS pvz_name, p.city_id, c.name AS city_name,
                    b.qty_received, b.qty_available, b.qty_reserved, b.status, b.received_at
             FROM stock_batches b
             JOIN pvz p ON p.id = b.pvz_id
             JOIN cities c ON c.id = p.city_id
             WHERE b.item_id = ?
             ORDER BY b.received_at DESC'
        );
        $stmt->execute([$itemId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Проверка существования продавца. */
    public function sellerExists(string $sellerId): bool
    {
        $stmt = $this->db->get()->prepare('SELECT 1 FROM sellers WHERE id = ?');
        $stmt->execute([$sellerId]);

        return $stmt->fetchColumn() !== false;
    }

    /** Проверка существования активного ПВЗ. */
    public function pvzExists(string $pvzId): bool
    {
        $stmt = $this->db->get()->prepare('SELECT 1 FROM pvz WHERE id = ? AND is_active');
        $stmt->execute([$pvzId]);

        return $stmt->fetchColumn() !== false;
    }

    /** ПВЗ по ID или исключение 404. */
    public function requirePvz(string $pvzId): array
    {
        $stmt = $this->db->get()->prepare('SELECT * FROM pvz WHERE id = ?');
        $stmt->execute([$pvzId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new NotFoundException("PVZ $pvzId not found");
        }

        return $row;
    }

    private function newUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
