<?php

declare(strict_types=1);

namespace PhpHono\Router;

use Closure;

final readonly class RouteMatch
{
    /**
     * @param array<string, string> $params
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public Closure $handler,
        public array $params,
        public string $path,
        public array $metadata = [],
    ) {}
}
