<?php

declare(strict_types=1);

namespace PhpHono\Router;

interface RouterInterface
{
    /** @param array<string, mixed> $metadata */
    public function add(string $method, string $path, callable $handler, array $metadata = []): void;

    /** @return list<RouteMatch> */
    public function match(string $method, string $path): array;
}
