<?php

declare(strict_types=1);

namespace App\Modules\Stock\Application;

use App\Infrastructure\Database\ConnectionProvider;
use App\Modules\Stock\Infrastructure\StockRepository;
use App\Shared\Api\ConflictException;
use App\Shared\Api\NotFoundException;
use App\Shared\Api\ValidationException;

/**
 * Завозы партий: продавец объявляет завоз на конкретный ПВЗ,
 * ПВЗ принимает товар строго по завозу (без завоза приёмка невозможна).
 */
final class SupplyService
{
    public function __construct(
        private readonly ConnectionProvider $db,
        private readonly StockRepository $stock,
    ) {}

    /**
     * Продавец объявляет завоз партии на ПВЗ (POST /supplies).
     * @param array<string,mixed> $body seller_id, item_id, pvz_id, qty
     * @return array<string,mixed>
     */
    public function create(array $body, string $idempotencyKey): array
    {
        $sellerId = (string) ($body['seller_id'] ?? '');
        $itemId = (string) ($body['item_id'] ?? '');
        $pvzId = (string) ($body['pvz_id'] ?? '');
        $qty = filter_var($body['qty'] ?? null, FILTER_VALIDATE_INT);
        if ($sellerId === '' || $itemId === '' || $pvzId === '' || $qty === false || $qty < 1) {
            throw new ValidationException("Fields 'seller_id', 'item_id', 'pvz_id' (uuid) and 'qty' (int >= 1) are required");
        }

        // Товар принадлежит продавцу
        $stmt = $this->db->get()->prepare('SELECT 1 FROM items WHERE id = ? AND seller_id = ?');
        $stmt->execute([$itemId, $sellerId]);
        if ($stmt->fetchColumn() === false) {
            throw new ValidationException('Item does not belong to seller');
        }

        // ПВЗ активен
        $this->stock->requirePvz($pvzId);

        // Вместимость: завоз не должен превышать свободное место
        $cap = $this->pvzCapacity($pvzId);
        $used = $this->stock->availableUnits($pvzId);
        if ($used + $qty > $cap) {
            throw new ConflictException("Not enough capacity at PVZ: used $used/$cap, incoming $qty");
        }

        $id = $this->newUuid();
        $this->db->get()
            ->prepare('INSERT INTO supplies (id, seller_id, item_id, pvz_id, qty, idempotency_key) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$id, $sellerId, $itemId, $pvzId, $qty, $idempotencyKey]);

        return ['id' => $id, 'status' => 'CREATED', 'pvz_id' => $pvzId, 'item_id' => $itemId, 'qty' => $qty];
    }

    /**
     * Приёмка завоза ПВЗ (POST /supplies/{id}/accept): создаёт партию и движение INTAKE.
     * @return array<string,mixed>
     */
    public function accept(string $supplyId, string $idempotencyKey): array
    {
        if ($this->stock->movementExists($idempotencyKey)) {
            return ['status' => 'already_processed'];
        }

        $supply = $this->requireSupply($supplyId);

        return $this->db->transactional(function () use ($supply, $idempotencyKey): array {
            // Блокируем завоз: принять можно только один раз, только в статусе CREATED
            $stmt = $this->db->get()->prepare("UPDATE supplies SET status = 'ACCEPTED' WHERE id = ? AND status = 'CREATED'");
            $stmt->execute([$supply['id']]);
            if ($stmt->rowCount() !== 1) {
                throw new ConflictException("Supply already {$supply['status']}");
            }

            // Вместимость с учётом других партий
            $used = $this->stock->availableUnits($supply['pvz_id']);
            $cap = $this->pvzCapacity($supply['pvz_id']);
            if ($used + (int) $supply['qty'] > $cap) {
                throw new ConflictException("Not enough capacity at PVZ: used $used/$cap, incoming {$supply['qty']}");
            }

            $tariff = number_format((float) ($supply['storage_tariff_day'] ?? 0), 2, '.', '');
            $batchId = $this->stock->intake($supply['item_id'], $supply['pvz_id'], (int) $supply['qty'], $tariff);
            $this->stock->movement($batchId, null, 'INTAKE', (int) $supply['qty'], $idempotencyKey);

            return [
                'batch_id' => $batchId,
                'supply_id' => $supply['id'],
                'qty' => (int) $supply['qty'],
                'status' => 'ACCEPTED',
            ];
        });
    }

    /**
     * Список завозов: все или по ПВЗ (GET /supplies?pvz_id=).
     * @return array<int,array<string,mixed>>
     */
    public function list(?string $pvzId): array
    {
        $sql = 'SELECT s.id, s.seller_id, s.item_id, s.pvz_id, s.qty, s.status, s.created_at,
                       i.title AS item_title, p.name AS pvz_name, sl.name AS seller_name
                FROM supplies s
                JOIN items i ON i.id = s.item_id
                JOIN pvz p ON p.id = s.pvz_id
                JOIN sellers sl ON sl.id = s.seller_id';
        $params = [];
        if ($pvzId !== null) {
            $sql .= ' WHERE s.pvz_id = ?';
            $params[] = $pvzId;
        }
        $sql .= ' ORDER BY s.created_at DESC LIMIT 100';
        $stmt = $this->db->get()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Завоз по ID или 404. @return array<string,mixed> */
    public function requireSupply(string $supplyId): array
    {
        $stmt = $this->db->get()->prepare('SELECT * FROM supplies WHERE id = ?');
        $stmt->execute([$supplyId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new NotFoundException("Supply $supplyId not found");
        }

        return $row;
    }

    /** Вместимость ПВЗ (единицы хранения). */
    private function pvzCapacity(string $pvzId): int
    {
        $stmt = $this->db->get()->prepare('SELECT capacity_units FROM pvz WHERE id = ?');
        $stmt->execute([$pvzId]);
        $cap = $stmt->fetchColumn();

        return $cap === false ? 0 : (int) $cap;
    }

    /** UUID v4. */
    private function newUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
