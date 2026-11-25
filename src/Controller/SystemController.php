<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\SystemService;
use App\Shared\Api\ApiController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Системные эндпоинты: healthcheck и тестовое время. Логика в SystemService.
 */
final class SystemController extends ApiController
{
    public function __construct(
        private readonly SystemService $service,
    ) {}

    /** Healthcheck: GET /health. */
    public function health(): Response
    {
        $result = $this->service->health();

        return new \Symfony\Component\HttpFoundation\JsonResponse($result, $result['status'] === 'ok' ? 200 : 503);
    }

    /** Сдвиг времени: POST /system/time/advance. */
    public function timeAdvance(Request $request): Response
    {
        return self::handleExceptions(function () use ($request): Response {
            self::requireIdempotencyKey($request);
            $hours = filter_var(self::body($request)['hours'] ?? null, FILTER_VALIDATE_INT);

            return $this->json($this->service->timeAdvance($hours === false ? 0 : $hours));
        });
    }

    /** Сброс времени: POST /system/time/reset. */
    public function timeReset(Request $request): Response
    {
        return self::handleExceptions(function () use ($request): Response {
            self::requireIdempotencyKey($request);

            return $this->json($this->service->timeReset());
        });
    }
}
