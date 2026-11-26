<?php

declare(strict_types=1);

namespace App\Controller;

use App\Modules\Analytics\Application\AnalyticsService;
use App\Service\FrontendService;
use App\Shared\Api\ApiException;
use App\Shared\Api\ApiController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Эндпоинты для демо-фронтенда (адаптер данных под форму UI). Логика в FrontendService.
 * Отвечают СЫРЫМ JSON без обёртки {data}: код api.js читает поля с верхнего уровня.
 */
final class FrontendController extends ApiController
{
    public function __construct(
        private readonly FrontendService $service,
        private readonly AnalyticsService $analytics,
    ) {}

    /** Сырой JSON-ответ (без обёртки {data}) с обработкой доменных исключений. */
    private function raw(callable $fn): Response
    {
        try {
            return new JsonResponse($fn());
        } catch (ApiException $e) {
            return new JsonResponse(['error' => $e->getMessage()], $e->status());
        }
    }

    /** Справочники + заказы для первого экрана: GET /frontend/bootstrap. */
    public function bootstrap(): Response
    {
        return $this->raw(fn (): array => $this->service->bootstrap());
    }

    /** Предварительный расчёт маршрута: GET /frontend/quote?item_id=&qty=&target_pvz_id=&preference=. */
    public function quote(Request $request): Response
    {
        return $this->raw(fn (): array => $this->service->quote($request->query->all()));
    }

    /** Остатки товара по ПВЗ: GET /items/{id}/batches (серверный режим UI). */
    public function itemBatches(string $id): Response
    {
        return $this->raw(fn (): array => $this->service->itemBatches(strtolower($id)));
    }

    /** Остатки и загрузка ПВЗ: GET /pvz/{id}/stock (серверный режим UI). */
    public function pvzStock(string $id): Response
    {
        return $this->raw(fn (): array => $this->service->pvzStock(strtolower($id)));
    }

    /** Аналитика продавца: GET /analytics/seller/{id} (серверный режим UI). */
    public function sellerAnalytics(string $id): Response
    {
        return $this->raw(fn (): array => $this->analytics->sellerAnalytics(strtolower($id))['financials']);
    }

    /** Аналитика ПВЗ: GET /analytics/pvz/{id} (серверный режим UI). */
    public function pvzAnalytics(string $id): Response
    {
        return $this->raw(fn (): array => $this->analytics->pvzAnalytics(strtolower($id))['financials']);
    }

    /** Уведомления: GET /notifications (серверный режим UI). */
    public function notifications(Request $request): Response
    {
        return $this->raw(function () use ($request): array {
            $pvzId = $request->query->get('pvz') ?? $request->query->get('pvz_id');

            return $this->service->notifications(
                is_string($pvzId) && preg_match('/^[0-9a-f-]{36}$/i', $pvzId) ? strtolower($pvzId) : null,
            );
        });
    }

    /** Детали заказа: GET /orders/{id} (серверный режим UI). */
    public function orderDetails(string $id): Response
    {
        return $this->raw(fn (): array => $this->service->orderDetails(strtolower($id)));
    }

    /** Список завозов (фильтр ?pvz_id=, без тела; продавец — свои). */
    public function supplies(Request $request): Response
    {
        return $this->raw(function () use ($request): array {
            $pvzId = $request->query->get('pvz_id');

            return $this->service->suppliesList(
                is_string($pvzId) && preg_match('/^[0-9a-f-]{36}$/i', $pvzId) ? strtolower($pvzId) : null,
            );
        });
    }

    /** Создание завоза продавцом: POST /frontend/supplies. */
    public function createSupply(Request $request): Response
    {
        return $this->raw(function () use ($request): array {
            $key = self::requireIdempotencyKey($request);

            return $this->service->createSupply(self::body($request), $key);
        });
    }

    /** Приёмка завоза ПВЗ: POST /frontend/supplies/{id}/accept. */
    public function acceptSupply(Request $request, string $id): Response
    {
        return $this->raw(function () use ($request, $id): array {
            $key = self::requireIdempotencyKey($request);

            return $this->service->acceptSupply(strtolower($id), $key);
        });
    }
    /** Создание товара от демо-продавца: POST /frontend/items (payload name/kg/vol/price). */
    public function createItem(Request $request): Response
    {
        return $this->raw(function () use ($request): array {
            self::requireIdempotencyKey($request);

            return $this->service->createItem(self::body($request));
        });
    }
}
