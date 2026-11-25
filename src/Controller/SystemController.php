<?php

declare(strict_types=1);

namespace App\Controller;

use App\Infrastructure\Database\ConnectionProvider;
use App\Shared\Api\ApiController;
use App\Shared\Time\Clock;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class SystemController extends ApiController
{
    public function __construct(
        private readonly ConnectionProvider $db,
        private readonly Clock $clock,
    ) {}

    /** Healthcheck: GET /health. */
    public function health(): Response
    {
        try {
            $ok = $this->db->get()->query('SELECT 1')->fetchColumn() !== false;
        } catch (\Throwable) {
            $ok = false;
        }

        return new JsonResponse(
            ['status' => $ok ? 'ok' : 'db_down', 'service' => 'wildb-backend'],
            $ok ? 200 : 503,
        );
    }

    /** Сдвиг времени: POST /system/time/advance. */
    public function timeAdvance(Request $request): Response
    {
        return self::handleExceptions(function () use ($request): Response {
            self::requireIdempotencyKey($request);
            $body = self::body($request);
            $hours = filter_var($body['hours'] ?? null, FILTER_VALIDATE_INT);
            if ($hours === false || $hours < 1) {
                throw new \App\Shared\Api\ValidationException("Field 'hours' must be an integer >= 1");
            }

            $now = $this->clock->advance($hours);

            return $this->json(['hours_added' => $hours, 'now_override' => $now->format(\DateTimeInterface::ATOM)]);
        });
    }

    /** Сброс времени: POST /system/time/reset. */
    public function timeReset(Request $request): Response
    {
        return self::handleExceptions(function () use ($request): Response {
            self::requireIdempotencyKey($request);
            $this->db->get()->prepare("DELETE FROM time_control WHERE key = 'now_override'")->execute();

            return $this->json(['now_override' => null]);
        });
    }
}
