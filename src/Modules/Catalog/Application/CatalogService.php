<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Infrastructure\CatalogRepository;
use App\Shared\Api\NotFoundException;
use App\Shared\Api\ValidationException;

final class CatalogService
{
    public function __construct(
        private readonly CatalogRepository $repo,
    ) {}

    /** Создание карточки товара. */
    public function createItem(string $sellerId, array $body): array
    {
        if (!$this->repo->sellerExists($sellerId)) {
            throw new NotFoundException("Seller $sellerId not found");
        }

        $sku = trim((string) ($body['sku'] ?? ''));
        $title = trim((string) ($body['title'] ?? ''));
        if ($sku === '' || $title === '') {
            throw new ValidationException("Fields 'sku' and 'title' are required");
        }

        $weightKg = number_format((float) ($body['weight_kg'] ?? 0), 3, '.', '');
        $volumeM3 = number_format((float) ($body['volume_m3'] ?? 0), 4, '.', '');

        $id = $this->repo->createItem($sellerId, $sku, $title, $weightKg, $volumeM3);

        return ['id' => $id, 'seller_id' => $sellerId, 'sku' => $sku, 'title' => $title];
    }

    /** Остатки товара по ПВЗ (FIFO-партии). */
    public function itemBatches(string $itemId): array
    {
        if ($this->repo->findItem($itemId) === null) {
            throw new NotFoundException("Item $itemId not found");
        }

        return $this->repo->itemBatches($itemId);
    }
}
