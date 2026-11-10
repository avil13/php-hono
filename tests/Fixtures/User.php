<?php

declare(strict_types=1);

namespace PhpHono\Tests\Fixtures;

final readonly class User implements Identity
{
    public function __construct(
        private string $value,
    ) {}

    public function name(): string
    {
        return $this->value;
    }
}
