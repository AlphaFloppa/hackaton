<?php

declare(strict_types=1);

namespace App\Controller;

use App\Modules\Order\Application\OrderService;
use App\Shared\Api\ApiController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class OrderController extends ApiController
{
    public function __construct(
        private readonly OrderService $service,
    ) {}

    /** Создание заказа: POST /orders (требует Idempotency-Key). */
    public function create(Request $request): Response
    {
        return self::handleExceptions(function () use ($request): Response {
            self::requireIdempotencyKey($request);
            $body = self::body($request);

            return $this->json($this->service->createOrder($body), 201);
        });
    }

    /** Заказ с маршрутом, сегментами и расчётом: GET /orders/{id}. */
    public function get(string $id): Response
    {
        return $this->json($this->service->getOrder(strtolower($id)));
    }
}
