<?php

declare(strict_types=1);

namespace App\Modules\Stock\Application;

use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Notification\Application\NotificationService;
use App\Modules\Order\Infrastructure\OrderRepository;
use App\Modules\Stock\Infrastructure\StockRepository;
use App\Shared\Api\ConflictException;
use App\Shared\Api\ValidationException;
use App\Shared\Time\Clock;

/**
 * Жизненный цикл складских операций. Все операции идемпотентны через
 * idempotency_key в stock_movements (уникальный индекс) + проверку статуса заказа.
 */
final class StockService
{
    public function __construct(
        private readonly StockRepository $stock,
        private readonly OrderRepository $orders,
        private readonly LedgerService $ledger,
        private readonly NotificationService $notifications,
        private readonly Clock $clock,
    ) {}

    /** Приёмка партии на ПВЗ. */
    public function intake(string $pvzId, array $body, string $idempotencyKey): array
    {
        $itemId = (string) ($body['item_id'] ?? '');
        $qty = filter_var($body['qty'] ?? null, FILTER_VALIDATE_INT);
        $tariff = number_format((float) ($body['storage_tariff_day'] ?? 0), 2, '.', '');

        if ($itemId === '' || $qty === false || $qty < 1) {
            throw new ValidationException("Fields 'item_id' (uuid) and 'qty' (int >= 1) are required");
        }

        if ($this->stock->movementExists($idempotencyKey)) {
            return ['status' => 'already_processed'];
        }

        $batchId = $this->stock->transactional(function () use ($pvzId, $itemId, $qty, $tariff, $idempotencyKey): string {
            $batchId = $this->stock->intake($itemId, $pvzId, $qty, $tariff);
            $this->stock->movement($batchId, null, 'INTAKE', $qty, $idempotencyKey);

            return $batchId;
        });

        return ['batch_id' => $batchId, 'qty' => $qty, 'status' => 'ON_STORAGE'];
    }

    /** Остатки и загрузка ПВЗ. */
    public function pvzStock(string $pvzId): array
    {
        $pvz = $this->stock->requirePvz($pvzId);
        $used = $this->stock->availableUnits($pvzId);

        return [
            'pvz' => [
                'id'             => $pvz['id'],
                'name'           => $pvz['name'],
                'capacity_units' => (int) $pvz['capacity_units'],
                'used_units'     => $used,
                'load_percent'   => $pvz['capacity_units'] > 0
                    ? round($used / (int) $pvz['capacity_units'] * 100, 1)
                    : 0,
            ],
            'batches' => $this->stock->pvzStock($pvzId),
        ];
    }

    /** Сборка заказа: RESERVE на партии + проводка FEE_ASSEMBLY. */
    public function assemble(string $orderId, string $idempotencyKey): array
    {
        return $this->changeOrderStock($orderId, $idempotencyKey, function (array $order) use ($orderId, $idempotencyKey): array {
            if (!in_array($order['status'], ['CREATED', 'RESERVED'], true)) {
                throw new ConflictException("Order in status {$order['status']} cannot be assembled");
            }

            $point = OrderRepository::assemblyPoint($order);
            $remaining = (int) $order['qty'];
            $firstBatch = null;
            foreach ($this->stock->batchesForReserve($order['item_id'], $point) as $batch) {
                $qty = min($remaining, (int) $batch['qty_available']);
                $key = $firstBatch === null ? $idempotencyKey : $idempotencyKey . ':' . $batch['id'];
                $this->stock->reserve($batch['id'], $qty);
                $this->stock->movement($batch['id'], $orderId, 'RESERVE', $qty, $key);
                $firstBatch ??= $batch['id'];
                $remaining -= $qty;
                if ($remaining === 0) {
                    break;
                }
            }
            if ($remaining > 0) {
                throw new ConflictException('Недостаточно товара в ПВЗ сборки по выбранному маршруту.');
            }
            $this->orders->updateStatus($orderId, 'ASSEMBLED', $firstBatch);

            $this->ledger->entry(
                $orderId, $point, $order['seller_id'],
                'PVZ', 'FEE_ASSEMBLY', $this->fee($point, 'fee_assembly'),
                'Сборка заказа', $idempotencyKey . ':fee_assembly',
            );

            return ['order_id' => $orderId, 'status' => 'ASSEMBLED'];
        });
    }

    /** Выдача покупателю: COMMIT резерва + FEE_ISSUE. */
    public function issue(string $orderId, string $idempotencyKey): array
    {
        return $this->changeOrderStock($orderId, $idempotencyKey, function (array $order) use ($orderId, $idempotencyKey): array {
            if (!in_array($order['status'], ['ASSEMBLED', 'DELIVERED_TO_PVZ'], true)) {
                throw new ConflictException("Order in status {$order['status']} cannot be issued");
            }
            foreach ($this->reservations($order) as $index => $batch) {
                $qty = (int) $batch['qty'];
                $key = $index === 0 ? $idempotencyKey : $idempotencyKey . ':' . $batch['batch_id'];
                $this->stock->commitReserve($batch['batch_id'], $qty);
                $this->stock->movement($batch['batch_id'], $orderId, 'COMMIT', $qty, $key);
            }
            $this->orders->updateStatus($orderId, 'COMPLETED');

            $this->ledger->entry(
                $orderId, $order['target_pvz_id'], $order['seller_id'],
                'PVZ', 'FEE_ISSUE', $this->fee($order['target_pvz_id'], 'fee_issue'),
                'Выдача заказа', $idempotencyKey . ':fee_issue',
            );

            return ['order_id' => $orderId, 'status' => 'COMPLETED'];
        });
    }

    /** Возврат: RELEASE резерва (или возврат выданного) + уведомление. */
    public function returnOrder(string $orderId, string $idempotencyKey, ?string $reason): array
    {
        return $this->changeOrderStock($orderId, $idempotencyKey, function (array $order) use ($orderId, $idempotencyKey, $reason): array {
            if (!in_array($order['status'], ['ASSEMBLED', 'DELIVERED_TO_PVZ', 'COMPLETED'], true)) {
                throw new ConflictException("Order in status {$order['status']} cannot be returned");
            }

            foreach ($this->reservations($order) as $index => $batch) {
                $qty = (int) $batch['qty'];
                if ($order['status'] !== 'COMPLETED') {
                    $this->stock->releaseReserve($batch['batch_id'], $qty);
                }
                $key = $index === 0 ? $idempotencyKey : $idempotencyKey . ':' . $batch['batch_id'];
                $this->stock->movement($batch['batch_id'], $orderId, 'RETURN', $qty, $key);
            }
            $this->orders->updateStatus($orderId, 'RETURNED');

            $this->ledger->entry(
                $orderId, $order['target_pvz_id'], $order['seller_id'],
                'PVZ', 'REFUND', $this->fee($order['target_pvz_id'], 'fee_issue'),
                $reason ?? 'Возврат заказа', $idempotencyKey . ':refund',
            );

            $this->notifications->add(
                $order['target_pvz_id'], $orderId,
                'RETURN', 'Заказ возвращён' . ($reason !== null ? ": $reason" : ''),
            );

            return ['order_id' => $orderId, 'status' => 'RETURNED'];
        });
    }

    private function changeOrderStock(string $orderId, string $key, callable $operation): array
    {
        return $this->stock->transactional(function () use ($orderId, $key, $operation): array {
            $order = $this->orders->requireOrder($orderId, lock: true);
            if ($this->stock->movementExists($key)) {
                return ['status' => 'already_processed'];
            }

            return $operation($order);
        });
    }

    private function reservations(array $order): array
    {
        $batches = $this->stock->orderReservations($order['id']);
        if ($batches === [] && $order['source_batch_id'] !== null) {
            return [['batch_id' => $order['source_batch_id'], 'qty' => $order['qty']]];
        }
        if (array_sum(array_column($batches, 'qty')) !== (int) $order['qty']) {
            throw new ConflictException('Резерв по партиям не совпадает с количеством в заказе.');
        }

        return $batches;
    }

    /** Начисление хранения за дни (сценарий «длительное хранение», вызывается вручную/кроном). */
    public function accrueStorage(string $pvzId): array
    {
        return $this->stock->transactional(fn (): array => $this->accrueStorageAt($pvzId, $this->clock->now()));
    }

    /** Вызывается внутри транзакции начисления или сдвига времени. */
    public function accrueStorageAt(string $pvzId, \DateTimeImmutable $now): array
    {
        $this->stock->requirePvz($pvzId, lock: true);
        $batches = $this->stock->pvzStock($pvzId);
        $billedDays = $this->ledger->billedStorageDays($pvzId);
        $total = '0';
        $count = 0;

        foreach ($batches as $b) {
            $units = (int) $b['qty_available'] + (int) $b['qty_reserved'];
            if ($units === 0 || !in_array($b['status'], ['ON_STORAGE', 'DELIVERED'], true)) {
                continue;
            }

            $id = $b['batch_id'];
            $days = Clock::wholeDaysSince($b['received_at'], $now);
            if ($days >= 7) {
                $this->notifications->addOnce($pvzId, 'STORAGE', "Партия {$b['sku']} ($id) хранится 7 суток или дольше");
            }

            $daysToBill = $days - ($billedDays[$id] ?? 0);
            $tariff = (string) $b['storage_tariff_day'];
            if ($daysToBill <= 0 || bccomp($tariff, '0', 2) <= 0) {
                continue;
            }

            $amount = bcmul($tariff, (string) ($units * $daysToBill), 2);
            if ($this->ledger->entry(
                null, $pvzId, $b['seller_id'], 'PVZ', 'FEE_STORAGE', $amount,
                "Хранение {$b['sku']}, $units ед. × $daysToBill сут.", "storage:$id:$days",
            )) {
                $total = bcadd($total, $amount, 2);
                $count++;
            }
        }

        return ['pvz_id' => $pvzId, 'entries' => $count, 'total' => $total];
    }

    private function fee(string $pvzId, string $field): string
    {
        $pvz = $this->stock->requirePvz($pvzId);

        return number_format((float) $pvz[$field], 2, '.', '');
    }
}
