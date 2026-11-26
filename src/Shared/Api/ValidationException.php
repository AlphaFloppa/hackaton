<?php

declare(strict_types=1);

namespace App\Shared\Api;

final class ValidationException extends ApiException
{
    public function status(): int { return 422; }
}
