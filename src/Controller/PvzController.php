<?php

declare(strict_types=1);

namespace App\Controller;

use App\Modules\Stock\Application\StockService;
use App\Shared\Api\ApiController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PvzController extends ApiController
{
    public function __construct(
        private readonly StockService $stock,
    ) {}

    /** Приёмка партии: POST /pvz/{pvzId}/intake. */
    public function intake(Request $request, string $pvzId): Response
    {
        return self::handleExceptions(function () use ($request, $pvzId): Response {
            $key = self::requireIdempotencyKey($request);

            return $this->json($this->stock->intake(strtolower($pvzId), self::body($request), $key), 201);
        });
    }

    /** Остатки ПВЗ: GET /pvz/{pvzId}/stock. */
    public function stock(string $pvzId): Response
    {
        return self::handleExceptions(fn (): Response => $this->json($this->stock->pvzStock(strtolower($pvzId))));
    }

    /** Начисление хранения: POST /pvz/{pvzId}/storage/accrue. */
    public function accrueStorage(Request $request, string $pvzId): Response
    {
        return self::handleExceptions(function () use ($request, $pvzId): Response {
            self::requireIdempotencyKey($request);

            return $this->json($this->stock->accrueStorage(strtolower($pvzId)));
        });
    }
}
