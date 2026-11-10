<?php

declare(strict_types=1);

namespace PhpHono\Examples\ContextDi;

final readonly class RequestMetadata
{
    public function __construct(
        public string $traceId,
    ) {}
}
