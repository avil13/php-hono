<?php

declare(strict_types=1);

namespace PhpHono\Examples\ContextDi;

final readonly class TenantContext
{
    public function __construct(
        public string $id,
    ) {}
}
