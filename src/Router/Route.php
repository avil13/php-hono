<?php

declare(strict_types=1);

namespace PhpHono\Router;

use Closure;

/** @internal */
final readonly class Route
{
    /**
     * @param list<string> $parameterNames
     * @param array<string, mixed> $metadata
     * Immutable route record mirrors the registration and compiled pattern fields.
     * @mago-expect lint:excessive-parameter-list
     */
    public function __construct(
        public string $method,
        public string $path,
        public Closure $handler,
        public string $pattern,
        public array $parameterNames,
        public array $metadata,
    ) {}
}
