<?php

declare(strict_types=1);

namespace App\Controller;

use App\Modules\Catalog\Application\CatalogService;
use App\Shared\Api\ApiController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class CatalogController extends ApiController
{
    public function __construct(
        private readonly CatalogService $service,
    ) {}

    /** Создание товара: POST /items. */
    public function create(Request $request): Response
    {
        return self::handleExceptions(function () use ($request): Response {
            $body = self::body($request);
            $sellerId = self::uuid($body, 'seller_id');

            return $this->json($this->service->createItem($sellerId, $body), 201);
        });
    }

    /** Остатки товара по ПВЗ: GET /items/{id}/batches. */
    public function batches(string $id): Response
    {
        return self::handleExceptions(fn (): Response => $this->json($this->service->itemBatches(strtolower($id))));
    }
}
