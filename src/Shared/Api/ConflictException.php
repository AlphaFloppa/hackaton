<?php

declare(strict_types=1);

namespace App\Shared\Api;

final class ConflictException extends ApiException
{
    public function status(): int { return 409; }
}
