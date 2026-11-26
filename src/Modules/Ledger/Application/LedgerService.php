<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Application;

use App\Modules\Ledger\Infrastructure\LedgerRepository;

/**
 * Финансовый журнал: односторонние проводки с idempotency_key (уникальный индекс в БД).
 */
final class LedgerService
{
    public function __construct(
        private readonly LedgerRepository $repo,
    ) {}

    /** Односторонняя проводка с идемпотентностью; false, если ключ уже проведён. */
    public function entry(
        ?string $orderId,
        ?string $pvzId,
        ?string $sellerId,
        string $party,
        string $entryType,
        string $amount,
        ?string $reason,
        string $idempotencyKey,
    ): bool {
        return $this->repo->add([
            'order_id'       => $orderId,
            'pvz_id'         => $pvzId,
            'seller_id'      => $sellerId,
            'party'          => $party,
            'entry_type'     => $entryType,
            'amount'         => $amount,
            'reason'         => $reason,
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    public function billedStorageDays(string $pvzId): array
    {
        return $this->repo->billedStorageDays($pvzId);
    }
}
