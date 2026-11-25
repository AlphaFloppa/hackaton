<?php

declare(strict_types=1);

namespace App\Shared\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * Базовый контроллер: JSON-ответы, разбор тела, идемпотентность, обработка доменных исключений.
 * Наследуется от AbstractController: симфони сама делает наследников публичными сервисами.
 */
abstract class ApiController extends AbstractController
{
    /** JSON-ответ в формате {data: ...}. Сигнатура совместима с AbstractController::json(). */
    protected function json(mixed $data, int $status = 200, array $headers = [], array $context = []): JsonResponse
    {
        return new JsonResponse(['data' => $data], $status, $headers);
    }

    protected static function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    protected static function body(Request $request): array
    {
        if ($request->getContent() === '') {
            return [];
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            throw new BadRequestException('Invalid JSON body');
        }

        return $data;
    }

    /** Требует непустой Idempotency-Key на POST. */
    protected static function requireIdempotencyKey(Request $request): string
    {
        $key = (string) $request->headers->get('Idempotency-Key', '');
        if ($key === '') {
            throw new BadRequestException('Idempotency-Key header is required');
        }

        return $key;
    }

    protected static function uuid(array $body, string $field): string
    {
        $v = (string) ($body[$field] ?? '');
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v)) {
            throw new ValidationException("Field '$field' must be a valid UUID");
        }

        return strtolower($v);
    }

    protected static function intField(array $body, string $field, int $min = 1): int
    {
        $v = filter_var($body[$field] ?? null, FILTER_VALIDATE_INT);
        if ($v === false || $v < $min) {
            throw new ValidationException("Field '$field' must be an integer >= $min");
        }

        return $v;
    }

    protected static function enumField(array $body, string $field, array $allowed): string
    {
        $v = (string) ($body[$field] ?? '');
        if (!in_array($v, $allowed, true)) {
            throw new ValidationException("Field '$field' must be one of: " . implode('|', $allowed));
        }

        return $v;
    }

    public static function handleExceptions(callable $fn): Response
    {
        try {
            return $fn();
        } catch (ApiException $e) {
            return self::error($e->status(), $e->title(), $e->getMessage());
        } catch (\PDOException $e) {
            // 23505 = unique violation (идемпотентность), 23514 = check
            if ($e->getCode() === '23505') {
                return self::error(409, 'Conflict', 'Duplicate resource (idempotency key or unique constraint)');
            }

            return self::error(500, 'DatabaseError', $e->getMessage());
        }
    }
}
