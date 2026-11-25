<?php

declare(strict_types=1);

namespace App\Modules\Stock\Infrastructure;

use App\Infrastructure\Database\ConnectionProvider;
use App\Shared\Api\ConflictException;
use App\Shared\Api\NotFoundException;

final class StockRepository
{
    public function __construct(
        private readonly ConnectionProvider $db,
    ) {}

    /** Генерация UUID v4. */
    public function newUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /** Запись складского движения. */
    public function movement(string $batchId, ?string $orderId, string $type, int $qty, string $idempotencyKey): void
    {
        $this->db->get()
            ->prepare('INSERT INTO stock_movements (batch_id, order_id, type, qty, idempotency_key) VALUES (?, ?, ?, ?, ?)')
            ->execute([$batchId, $orderId, $type, $qty, $idempotencyKey]);
    }

    /** Проверка идемпотентности по ключу. */
    public function movementExists(string $idempotencyKey): bool
    {
        $stmt = $this->db->get()->prepare('SELECT 1 FROM stock_movements WHERE idempotency_key = ?');
        $stmt->execute([$idempotencyKey]);

        return $stmt->fetchColumn() !== false;
    }

    /** @return array<string,mixed>|null */
    public function findBatch(string $batchId): ?array
    {
        $stmt = $this->db->get()->prepare('SELECT * FROM stock_batches WHERE id = ? FOR UPDATE');
        $stmt->execute([$batchId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** FIFO: старейшая партия с достаточным остатком на ПВЗ. @return array<string,mixed>|null */
    public function findBatchForReserve(string $itemId, string $pvzId, int $qty): ?array
    {
        $stmt = $this->db->get()->prepare(
            "SELECT * FROM stock_batches
             WHERE item_id = ? AND pvz_id = ? AND qty_available >= ? AND status = 'ON_STORAGE'
             ORDER BY received_at
             FOR UPDATE"
        );
        $stmt->execute([$itemId, $pvzId, $qty]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return array<int,array<string,mixed>> */
    public function pvzStock(string $pvzId): array
    {
        $stmt = $this->db->get()->prepare(
            'SELECT b.id AS batch_id, b.item_id, i.sku, i.title,
                    b.qty_received, b.qty_available, b.qty_reserved, b.status, b.received_at,
                    b.storage_tariff_day
             FROM stock_batches b JOIN items i ON i.id = b.item_id
             WHERE b.pvz_id = ? ORDER BY b.received_at DESC'
        );
        $stmt->execute([$pvzId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Занятые единицы хранения (available + reserved). */
    public function availableUnits(string $pvzId): int
    {
        $stmt = $this->db->get()->prepare(
            "SELECT COALESCE(SUM(qty_available + qty_reserved), 0) FROM stock_batches
             WHERE pvz_id = ? AND status IN ('ON_STORAGE','IN_TRANSIT')"
        );
        $stmt->execute([$pvzId]);

        return (int) $stmt->fetchColumn();
    }

    /** available -> reserved с проверкой остатка. */
    public function reserve(string $batchId, int $qty): void
    {
        $stmt = $this->db->get()->prepare(
            'UPDATE stock_batches SET qty_available = qty_available - ?, qty_reserved = qty_reserved + ?
             WHERE id = ? AND qty_available >= ?'
        );
        $stmt->execute([$qty, $qty, $batchId, $qty]);
        if ($stmt->rowCount() !== 1) {
            throw new ConflictException('Not enough stock available to reserve');
        }
    }

    /** Списание резерва при выдаче. */
    public function commitReserve(string $batchId, int $qty): void
    {
        $stmt = $this->db->get()->prepare(
            'UPDATE stock_batches SET qty_reserved = qty_reserved - ?, status = ?
             WHERE id = ? AND qty_reserved >= ?'
        );
        $stmt->execute([$qty, 'DELIVERED', $batchId, $qty]);
        if ($stmt->rowCount() !== 1) {
            throw new ConflictException('Reserved qty mismatch on commit');
        }
    }

    /** Возврат резерва в доступные. */
    public function releaseReserve(string $batchId, int $qty): void
    {
        $stmt = $this->db->get()->prepare(
            'UPDATE stock_batches SET qty_reserved = qty_reserved - ?, qty_available = qty_available + ?
             WHERE id = ? AND qty_reserved >= ?'
        );
        $stmt->execute([$qty, $qty, $batchId, $qty]);
        if ($stmt->rowCount() !== 1) {
            throw new ConflictException('Reserved qty mismatch on release');
        }
    }

    /** Создание новой партии (INTAKE). */
    public function intake(string $itemId, string $pvzId, int $qty, string $tariffDay): string
    {
        $id = $this->newUuid();
        $stmt = $this->db->get()->prepare(
            'INSERT INTO stock_batches (id, item_id, pvz_id, qty_received, qty_available, storage_tariff_day)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$id, $itemId, $pvzId, $qty, $qty, $tariffDay]);

        return $id;
    }

    /** @return array<string,mixed> */
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

    /** @template T @param callable(\PDO):T $fn @return T */
    public function transactional(callable $fn): mixed
    {
        return $this->db->transactional($fn);
    }
}
