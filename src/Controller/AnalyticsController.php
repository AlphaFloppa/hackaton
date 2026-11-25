<?php

declare(strict_types=1);

namespace App\Controller;

use App\Modules\Analytics\Application\AnalyticsService;
use App\Shared\Api\ApiController;
use Symfony\Component\HttpFoundation\Response;

final class AnalyticsController extends ApiController
{
    public function __construct(
        private readonly AnalyticsService $service,
    ) {}

    /** Аналитика продавца: GET /analytics/seller/{id}. */
    public function seller(string $id): Response
    {
        return self::handleExceptions(fn (): Response => $this->json($this->service->sellerAnalytics(strtolower($id))));
    }

    /** Аналитика ПВЗ: GET /analytics/pvz/{id}. */
    public function pvz(string $id): Response
    {
        return self::handleExceptions(fn (): Response => $this->json($this->service->pvzAnalytics(strtolower($id))));
    }
}
