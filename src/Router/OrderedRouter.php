<?php

declare(strict_types=1);

namespace PhpHono\Router;

use Closure;
use InvalidArgumentException;
use LogicException;
use PhpHono\HttpException;
use RuntimeException;

/**
 * Ordered matching and its boundary validation share one registration store.
 * @mago-expect lint:cyclomatic-complexity
 */
final class OrderedRouter implements RouterInterface
{
    /** @var list<Route> */
    private array $routes = [];
    private bool $frozen = false;

    public function __construct(
        private readonly bool $strict = true,
        private readonly int $pathLimit = 8192,
        private readonly int $matchLimit = 10_000,
        private readonly int $depthLimit = 1000,
    ) {
        if ($pathLimit < 1 || $matchLimit < 1 || $depthLimit < 1) {
            throw new InvalidArgumentException('Router limits must be positive.');
        }
    }

    /** @param array<string, mixed> $metadata */
    public function add(string $method, string $path, callable $handler, array $metadata = []): void
    {
        if ($this->frozen) {
            throw new LogicException('Routes cannot be registered after dispatch begins.');
        }
        if (preg_match('/\A[!#$%&\x27*+.^_`|~0-9A-Za-z-]+\z/D', $method) !== 1) {
            throw new InvalidArgumentException('Invalid HTTP method.');
        }

        [$pattern, $names] = PatternCompiler::compile($path, $this->matchLimit, $this->depthLimit);
        $this->routes[] = new Route(
            strtoupper($method),
            $path,
            Closure::fromCallable($handler),
            $pattern,
            $names,
            $metadata,
        );
    }

    /** @return list<RouteMatch> */
    public function match(string $method, string $path): array
    {
        $this->freeze();
        $this->validatePath($path);
        if (!$this->strict && $path !== '/' && str_ends_with($path, '/')) {
            $path = substr($path, offset: 0, length: -1);
        }
        $method = strtoupper($method);
        $result = [];

        foreach ($this->routes as $route) {
            if ($route->method !== 'ALL' && $route->method !== '*' && $route->method !== $method) {
                continue;
            }

            $matched = preg_match($route->pattern, $path, $captures, PREG_UNMATCHED_AS_NULL);
            if ($matched === false) {
                throw new RuntimeException('Route matching failed: ' . preg_last_error_msg());
            }
            if ($matched === 0) {
                continue;
            }

            $params = [];
            foreach ($route->parameterNames as $index => $name) {
                $value = $captures[$index + 1] ?? null;
                if ($value !== null && !array_key_exists($name, $params)) {
                    $params[$name] = rawurldecode($value);
                }
            }
            $result[] = new RouteMatch($route->handler, $params, $route->path, $route->metadata);
        }

        return $result;
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    private function validatePath(string $path): void
    {
        if (strlen($path) > $this->pathLimit) {
            throw new HttpException(414, 'URI Too Long');
        }
        if (!str_starts_with($path, '/') || preg_match('/%(?![0-9A-Fa-f]{2})/', $path) === 1) {
            throw new HttpException(400, 'Malformed path');
        }
        $decoded = rawurldecode($path);
        if (preg_match('//u', $decoded) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $decoded) === 1) {
            throw new HttpException(400, 'Malformed path');
        }
    }
}
