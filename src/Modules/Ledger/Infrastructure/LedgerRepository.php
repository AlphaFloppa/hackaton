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
    public function add(array $e): bool
    {
        $stmt = $this->db->get()->prepare(
            'INSERT INTO ledger_entries (order_id, pvz_id, seller_id, party, entry_type, amount, reason, idempotency_key)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (idempotency_key) DO NOTHING'
        );
        $stmt->execute([
            $e['order_id'] ?? null,
            $e['pvz_id'] ?? null,
            $e['seller_id'] ?? null,
            $e['party'],
            $e['entry_type'],
            $e['amount'],
            $e['reason'] ?? null,
            $e['idempotency_key'] ?? null,
        ]);

        return $stmt->rowCount() === 1;
    }

    /** Последний оплаченный день по каждой партии, включая ранее записанные проводки. */
    public function billedStorageDays(string $pvzId): array
    {
        $stmt = $this->db->get()->prepare(
            "SELECT split_part(idempotency_key, ':', 2), MAX(split_part(idempotency_key, ':', 3)::integer)
             FROM ledger_entries
             WHERE pvz_id = ? AND party = 'PVZ' AND entry_type = 'FEE_STORAGE'
               AND idempotency_key ~ '^storage:[0-9a-f-]{36}:[0-9]{1,7}$'
             GROUP BY 1"
        );
        $stmt->execute([$pvzId]);

        return $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
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
                COALESCE(SUM(CASE WHEN e.entry_type = 'REFUND' THEN -ABS(e.amount) ELSE e.amount END)
                    FILTER (WHERE e.party = 'PVZ' AND e.entry_type IN ('FEE_INTAKE','FEE_ASSEMBLY','FEE_ISSUE','FEE_STORAGE','REFUND')), 0) AS pvz_fees,
                COALESCE(SUM(e.amount) FILTER (WHERE e.entry_type = 'REVENUE'), 0) AS seller_revenue,
                COALESCE(SUM(e.amount) FILTER (WHERE e.entry_type = 'REFUND'), 0) AS refunds
             FROM ledger_entries e
             LEFT JOIN orders o ON e.seller_id IS NULL AND o.id = e.order_id
             LEFT JOIN stock_batches b ON e.seller_id IS NULL AND e.entry_type = 'FEE_STORAGE'
                       AND e.idempotency_key LIKE 'storage:%'
                       AND b.id::text = split_part(e.idempotency_key, ':', 2)
             LEFT JOIN items i ON i.id = b.item_id
             WHERE COALESCE(e.seller_id, o.seller_id, i.seller_id) = ?"
        );
        $stmt->execute([$sellerId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }
}
