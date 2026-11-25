<?php

declare(strict_types=1);

namespace App\Controller;

use App\Modules\Stock\Application\StockService;
use App\Shared\Api\ApiController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Складские операции над заказом (роль P — приложение ПВЗ).
 */
final class OrderOpsController extends ApiController
{
    public function __construct(
        private readonly StockService $stock,
    ) {}

    /** Сборка заказа: POST /orders/{id}/assemble. */
    public function assemble(Request $request, string $id): Response
    {
        return self::handleExceptions(function () use ($request, $id): Response {
            $key = self::requireIdempotencyKey($request);

            return $this->json($this->stock->assemble(strtolower($id), $key));
        });
    }

    /** Выдача заказа: POST /orders/{id}/issue. */
    public function issue(Request $request, string $id): Response
    {
        return self::handleExceptions(function () use ($request, $id): Response {
            $key = self::requireIdempotencyKey($request);

            return $this->json($this->stock->issue(strtolower($id), $key));
        });
    }

    /** Возврат заказа: POST /orders/{id}/return. */
    public function returnOrder(Request $request, string $id): Response
    {
        return self::handleExceptions(function () use ($request, $id): Response {
            $key = self::requireIdempotencyKey($request);
            $body = self::body($request);

            return $this->json($this->stock->returnOrder(strtolower($id), $key, $body['reason'] ?? null));
        });
    }
}
