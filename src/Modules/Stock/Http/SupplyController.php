<?php

declare(strict_types=1);

namespace App\Modules\Stock\Http;

use App\Modules\Stock\Application\SupplyService;
use App\Shared\Api\ApiController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Завозы партий: продавец объявляет, ПВЗ принимает.
 */
final class SupplyController extends ApiController
{
    public function __construct(
        private readonly SupplyService $service,
    ) {}

    /** Продавец объявляет завоз: POST /supplies (seller_id, item_id, pvz_id, qty). */
    public function create(Request $request): Response
    {
        return self::handleExceptions(function () use ($request): Response {
            $key = self::requireIdempotencyKey($request);

            return $this->json($this->service->create(self::body($request), $key), 201);
        });
    }

    /** Список завозов: GET /supplies?pvz_id=... (фильтр опционален). */
    public function list(Request $request): Response
    {
        return self::handleExceptions(function () use ($request): Response {
            $pvzId = $request->query->get('pvz_id');

            return $this->json($this->service->list(
                is_string($pvzId) && preg_match('/^[0-9a-f-]{36}$/i', $pvzId) ? strtolower($pvzId) : null,
            ));
        });
    }

    /** ПВЗ принимает завоз: POST /supplies/{id}/accept. */
    public function accept(Request $request, string $id): Response
    {
        return self::handleExceptions(function () use ($request, $id): Response {
            $key = self::requireIdempotencyKey($request);

            return $this->json($this->service->accept(strtolower($id), $key));
        });
    }
}
