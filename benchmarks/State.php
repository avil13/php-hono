<?php

declare(strict_types=1);

namespace PhpHono\Benchmarks;

final readonly class State
{
    public function __construct(
        public string $value,
    ) {}
}
