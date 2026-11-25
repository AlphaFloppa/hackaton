<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\StubService;
use App\Shared\Api\ApiController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * STUB-эндпоинты внутреннего вызова (Sys): обработка на хабе и магистраль.
 * Логика в StubService.
 */
final class StubController extends ApiController
{
    public function __construct(
        private readonly StubService $service,
    ) {}

    /** STUB: обработка на хабе: POST /stub/hub/handle. */
    public function hubHandle(Request $request): Response
    {
        return self::handleExceptions(function () use ($request): Response {
            $orderId = self::uuid(self::body($request), 'order_id');

            return $this->json($this->service->hubHandle($orderId));
        });
    }

    /** STUB: магистраль: POST /stub/trunk/move. */
    public function trunkMove(Request $request): Response
    {
        return self::handleExceptions(function () use ($request): Response {
            $body = self::body($request);
            $orderId = self::uuid($body, 'order_id');
            $chargeableKg = max(0.0, (float) ($body['chargeable_kg'] ?? 1.0));
            $distanceKm = max(0.0, (float) ($body['distance_km'] ?? 0.0));

            return $this->json($this->service->trunkMove($orderId, $chargeableKg, $distanceKm));
        });
    }
}
