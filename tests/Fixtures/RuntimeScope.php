<?php

declare(strict_types=1);

namespace PhpHono\Tests\Fixtures;

final class RuntimeScope
{
    public int $touches = 0;
    public bool $closed = false;

    public function __construct(
        public readonly string $tenant,
    ) {}
}
