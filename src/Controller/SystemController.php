<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\SystemService;
use App\Shared\Api\ApiController;
use App\Shared\Api\ValidationException;
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
            $key = self::requireIdempotencyKey($request);
            $body = self::body($request);
            $hours = $body['hours'] ?? null;
            $hours = is_int($hours) || is_string($hours) ? filter_var($hours, FILTER_VALIDATE_INT) : false;
            $accrueStorage = $body['accrue_storage'] ?? false;
            if (!is_bool($accrueStorage)) {
                throw new ValidationException("Field 'accrue_storage' must be a boolean");
            }

            return $this->json($this->service->timeAdvance($hours === false ? 0 : $hours, $key, $accrueStorage));
        });
    }

    /** Сброс времени: POST /system/time/reset. */
    public function timeReset(Request $request): Response
    {
        return self::handleExceptions(function () use ($request): Response {
            return $this->json($this->service->timeReset(self::requireIdempotencyKey($request)));
        });
    }
}
