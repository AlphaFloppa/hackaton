<?php

declare(strict_types=1);

namespace App\Controller;

use App\Modules\Notification\Application\NotificationService;
use App\Shared\Api\ApiController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class NotificationController extends ApiController
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    /** Уведомления: GET /notifications?pvz_id=... */
    public function list(Request $request): Response
    {
        return self::handleExceptions(function () use ($request): Response {
            $pvzId = $request->query->get('pvz_id');
            if ($pvzId !== null && !preg_match('/^[0-9a-f-]{36}$/i', (string) $pvzId)) {
                $pvzId = null;
            }

            return $this->json($this->notifications->forPvz($pvzId !== null ? strtolower((string) $pvzId) : null));
        });
    }

    /** Отметить прочитанными: POST /notifications/read-all. */
    public function readAll(Request $request): Response
    {
        return self::handleExceptions(function () use ($request): Response {
            $pvzId = $request->query->get('pvz_id');
            $count = $this->notifications->markAllRead(
                is_string($pvzId) && preg_match('/^[0-9a-f-]{36}$/i', $pvzId) ? strtolower($pvzId) : null,
            );

            return $this->json(['updated' => $count]);
        });
    }
}
