<?php

declare(strict_types=1);

namespace PhpHono\Internal;

use Closure;
use PhpHono\Router\RouterInterface;

/** @internal Shared only by basePath views; contains no request state. */
final class AppState
{
    public bool $frozen = false;
    /** @var list<array{method: string, path: string, handler: Closure, metadata: array<string, mixed>}> */
    public array $routes = [];

    public function __construct(
        public readonly RouterInterface $router,
    ) {}
}
