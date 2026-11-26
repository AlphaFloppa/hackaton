<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\IncidentService;
use App\Shared\Api\ApiController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Инциденты. Логика в IncidentService.
 */
final class IncidentController extends ApiController
{
    public function __construct(
        private readonly IncidentService $service,
    ) {}

    /** Фиксация инцидента: POST /incidents. */
    public function create(Request $request): Response
    {
        return self::handleExceptions(function () use ($request): Response {
            self::requireIdempotencyKey($request);

            return $this->json($this->service->create(self::body($request)), 201);
        });
    }
}
