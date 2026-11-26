<?php

declare(strict_types=1);

namespace Mesh\Routing;

use DomainException;

final class NoRoute extends DomainException
{
    public function __construct(public readonly array $quote)
    {
        parent::__construct($quote['reason']);
    }
}
