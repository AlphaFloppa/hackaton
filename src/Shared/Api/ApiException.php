<?php

declare(strict_types=1);

namespace App\Shared\Api;

abstract class ApiException extends \RuntimeException
{
    abstract public function status(): int;

    public function title(): string
    {
        return (new \ReflectionClass(static::class))->getShortName();
    }
}
