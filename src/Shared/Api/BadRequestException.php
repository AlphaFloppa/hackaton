<?php

declare(strict_types=1);

namespace App\Shared\Api;

final class BadRequestException extends ApiException
{
    public function status(): int { return 400; }
}
