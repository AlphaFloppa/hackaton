<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Infrastructure;

use App\Infrastructure\Database\ConnectionProvider;

final class LedgerRepository
{
    public function __construct(
        private readonly ConnectionProvider $db,
    ) {}

    /**
     * @param array<string,string|null> $e party, entry_type, amount, order_id?, pvz_id?, seller_id?, reason?, idempotency_key?
     */
    public function add(array $e): void
    {
        $this->db->get()
            ->prepare('INSERT INTO ledger_entries (order_id, pvz_id, seller_id, party, entry_type, amount, reason, idempotency_key)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $e['order_id'] ?? null,
                $e['pvz_id'] ?? null,
                $e['seller_id'] ?? null,
                $e['party'],
                $e['entry_type'],
                $e['amount'],
                $e['reason'] ?? null,
                $e['idempotency_key'] ?? null,
            ]);
    }

    /** Проверка ключа идемпотентности. */
    public function existsByIdempotencyKey(string $key): bool
    {
        $stmt = $this->db->get()->prepare('SELECT 1 FROM ledger_entries WHERE idempotency_key = ?');
        $stmt->execute([$key]);

        return $stmt->fetchColumn() !== false;
    }

    /** @return array<int,array<string,mixed>> */
    public function pvzTotals(string $pvzId): array
    {
        $stmt = $this->db->get()->prepare(
            'SELECT entry_type, party, SUM(amount) AS total, COUNT(*) AS cnt
             FROM ledger_entries WHERE pvz_id = ?
             GROUP BY entry_type, party ORDER BY entry_type'
        );
        $stmt->execute([$pvzId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function sellerTotals(string $sellerId): array
    {
        $stmt = $this->db->get()->prepare(
            "SELECT
                COALESCE(SUM(amount) FILTER (WHERE entry_type IN ('FEE_INTAKE','FEE_ASSEMBLY','FEE_ISSUE','FEE_STORAGE') AND party = 'PVZ'), 0) AS pvz_fees,
                COALESCE(SUM(amount) FILTER (WHERE entry_type = 'REVENUE'), 0) AS seller_revenue,
                COALESCE(SUM(amount) FILTER (WHERE entry_type = 'REFUND'), 0) AS refunds
             FROM ledger_entries WHERE seller_id = ?"
        );
        $stmt->execute([$sellerId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }
}
