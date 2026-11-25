<?php

declare(strict_types=1);

namespace App\Shared\Api;

final class NotFoundException extends ApiException
{
    public function status(): int { return 404; }
}
