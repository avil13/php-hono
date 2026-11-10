<?php

declare(strict_types=1);

namespace PhpHono;

use Closure;
use InvalidArgumentException;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PhpHono\Http\ResponseFactory;
use PhpHono\Internal\AppState;
use PhpHono\Internal\BodyStore;
use PhpHono\Internal\Compose;
use PhpHono\Router\OrderedRouter;
use PhpHono\Router\RouterInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * The Hono facade deliberately exposes HTTP verbs and lifecycle entry points together.
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 */
final class Hono implements RequestHandlerInterface
{
    private readonly AppState $state;
    private readonly ResponseFactory $factory;
    private string $prefix = '';
    /** @var (Closure(Throwable, Context): ResponseInterface)|null */
    private ?Closure $errorHandler = null;
    /** @var (Closure(Context): ResponseInterface)|null */
    private ?Closure $notFoundHandler = null;

    /**
     * Named limits and PSR factories remain explicit instead of an untyped options bag.
     *
     * @param array<string, mixed> $env
     * @mago-expect lint:excessive-parameter-list
     */
    public function __construct(
        private readonly bool $strict = true,
        private readonly array $env = [],
        private readonly int $maxBodySize = 8_388_608,
        private readonly int $maxPathLength = 8192,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?RouterInterface $router = null,
    ) {
        if ($maxBodySize < 0 || $maxPathLength < 1) {
            throw new InvalidArgumentException('Invalid request limits.');
        }
        $this->state = new AppState($router ?? new OrderedRouter($strict, $maxPathLength));
        $this->factory = new ResponseFactory($responseFactory, $streamFactory);
    }

    public function get(string $path, callable ...$handlers): static
    {
        return $this->on('GET', $path, ...$handlers);
    }

    public function post(string $path, callable ...$handlers): static
    {
        return $this->on('POST', $path, ...$handlers);
    }

    public function put(string $path, callable ...$handlers): static
    {
        return $this->on('PUT', $path, ...$handlers);
    }

    public function patch(string $path, callable ...$handlers): static
    {
        return $this->on('PATCH', $path, ...$handlers);
    }

    public function delete(string $path, callable ...$handlers): static
    {
        return $this->on('DELETE', $path, ...$handlers);
    }

    public function options(string $path, callable ...$handlers): static
    {
        return $this->on('OPTIONS', $path, ...$handlers);
    }

    public function query(string $path, callable ...$handlers): static
    {
        return $this->on('QUERY', $path, ...$handlers);
    }

    public function all(string $path, callable ...$handlers): static
    {
        return $this->on('*', $path, ...$handlers);
    }

    public function use(string $path, callable ...$middleware): static
    {
        $this->assertMutable();
        if ($middleware === []) {
            throw new InvalidArgumentException('Register at least one middleware.');
        }
        foreach ($middleware as $handler) {
            $this->register('*', self::joinPath($this->prefix, $path), Closure::fromCallable($handler), [
                'isMiddleware' => true,
            ]);
        }
        return $this;
    }

    /**
     * @param string|list<string> $methods
     * @param string|list<string> $paths
     */
    public function on(string|array $methods, string|array $paths, callable ...$handlers): static
    {
        $this->assertMutable();
        if ($handlers === []) {
            throw new InvalidArgumentException('Register at least one handler.');
        }
        foreach (is_array($methods) ? $methods : [$methods] as $method) {
            if (preg_match('/^[!#$%&\'*+.^_`|~a-zA-Z0-9-]+$/D', $method) !== 1) {
                throw new InvalidArgumentException('Invalid HTTP method token.');
            }
            foreach (is_array($paths) ? $paths : [$paths] as $path) {
                foreach ($handlers as $handler) {
                    $this->register(
                        strtoupper($method),
                        self::joinPath($this->prefix, $path),
                        Closure::fromCallable($handler),
                    );
                }
            }
        }
        return $this;
    }

    public function basePath(string $prefix): self
    {
        $this->assertMutable();
        self::validatePrefix($prefix);
        $view = clone $this;
        $view->prefix = rtrim(self::joinPath($this->prefix, $prefix), characters: '/');
        return $view;
    }

    public function route(string $prefix, self $child): static
    {
        $this->assertMutable();
        self::validatePrefix($prefix);
        $prefix = rtrim(self::joinPath($this->prefix, $prefix), characters: '/');
        foreach ($child->state->routes as $route) {
            $metadata = $route['metadata'];
            if (($metadata['onError'] ?? null) === null && $child->errorHandler !== null) {
                $metadata['onError'] = $child->errorHandler;
            }
            $this->register($route['method'], self::joinPath($prefix, $route['path']), $route['handler'], $metadata);
        }
        return $this;
    }

    /** @param callable(Throwable, Context): ResponseInterface $handler */
    public function onError(callable $handler): static
    {
        $this->errorHandler = Closure::fromCallable($handler);
        return $this;
    }

    /** @param callable(Context): ResponseInterface $handler */
    public function notFound(callable $handler): static
    {
        $this->notFoundHandler = Closure::fromCallable($handler);
        return $this;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->fetch($request, $this->env);
    }

    /** @param array<string, mixed> $env */
    public function fetch(ServerRequestInterface $request, array $env = []): ResponseInterface
    {
        $this->state->frozen = true;
        $context = null;
        try {
            $wrapped = new HonoRequest($request, [], new BodyStore($request->getBody(), $this->maxBodySize));
            $context = new Context($wrapped, $env, $this->factory, $this->notFoundHandler);
            $method = strtoupper($request->getMethod());
            $matches = $this->state->router->match($method === 'HEAD' ? 'GET' : $method, $wrapped->path());
            $response = new Compose($context, $matches, $this->errorHandler)->run();
        } catch (Throwable $error) {
            if ($context === null) {
                $safe = new Psr17Factory()->createServerRequest($request->getMethod(), 'http://localhost/');
                $context = new Context(new HonoRequest($safe), $env, $this->factory, $this->notFoundHandler);
            }
            $response = new Compose($context, [], $this->errorHandler)->handleError($error, $this->errorHandler);
        }
        return $this->normalizeResponse($response, strtoupper($request->getMethod()) === 'HEAD');
    }

    /**
     * @param array{method?: string, headers?: array<string, string|list<string>>, body?: string|StreamInterface} $options
     * @param array<string, mixed> $env
     */
    public function request(
        string|ServerRequestInterface $input,
        array $options = [],
        array $env = [],
    ): ResponseInterface {
        if (array_diff(array_keys($options), ['method', 'headers', 'body']) !== []) {
            throw new InvalidArgumentException('Supported request options are method, headers and body.');
        }
        $psr = new Psr17Factory();
        if (is_string($input)) {
            $uri = preg_match('~^https?://~i', $input) === 1
                ? $input
                : 'http://localhost/' . ltrim($input, characters: '/');
            $request = $psr->createServerRequest($options['method'] ?? 'GET', $uri);
            $parts = parse_url($input);
            if ($parts !== false) {
                $target = ($parts['path'] ?? '/') . (array_key_exists('query', $parts) ? '?' . $parts['query'] : '');
                $request = $request->withRequestTarget($target);
            }
        } else {
            $request = $input;
            if (array_key_exists('method', $options)) {
                $request = $request->withMethod($options['method']);
            }
        }
        foreach ($options['headers'] ?? [] as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if (array_key_exists('body', $options)) {
            $request = $request->withBody(
                is_string($options['body']) ? $psr->createStream($options['body']) : $options['body'],
            );
        }
        return $this->fetch($request, $env);
    }

    /** @return list<string> */
    public function allowedMethods(string $path): array
    {
        $probe = new OrderedRouter($this->strict, $this->maxPathLength);
        $methods = [];
        foreach ($this->state->routes as $route) {
            if ($route['method'] === 'HEAD' || ($route['metadata']['isMiddleware'] ?? false) === true) {
                continue;
            }
            $probe->add('*', $route['path'], $route['handler'], ['method' => $route['method']]);
        }
        foreach ($probe->match('GET', $path) as $match) {
            $method = $match->metadata['method'] ?? null;
            if ($method === '*') {
                return [];
            }
            if (is_string($method)) {
                $methods[$method] = true;
                if ($method === 'GET') {
                    $methods['HEAD'] = true;
                }
            }
        }
        return array_keys($methods);
    }

    /** @param array<string, mixed> $metadata */
    private function register(string $method, string $path, Closure $handler, array $metadata = []): void
    {
        $this->state->router->add($method, $path, $handler, $metadata);
        $this->state->routes[] = ['method' => $method, 'path' => $path, 'handler' => $handler, 'metadata' => $metadata];
    }

    private function assertMutable(): void
    {
        if ($this->state->frozen) {
            throw new LogicException('Routes cannot be registered after the first request.');
        }
    }

    private static function joinPath(string $prefix, string $path): string
    {
        if ($prefix === '') {
            return $path;
        }
        return rtrim($prefix, characters: '/') . '/' . ltrim($path, characters: '/');
    }

    private static function validatePrefix(string $prefix): void
    {
        if (!str_starts_with($prefix, '/') || strpbrk($prefix, characters: '*?:{}') !== false) {
            throw new InvalidArgumentException('A route prefix must be a literal absolute path.');
        }
    }

    private function normalizeResponse(ResponseInterface $response, bool $head): ResponseInterface
    {
        $status = $response->getStatusCode();
        if ($head || $status < 200 || in_array($status, [204, 205, 304], strict: true)) {
            $response = $response->withBody($this->factory->emptyBody());
            if ($status < 200 || $status === 204 || $status === 205) {
                $response = $response->withoutHeader('Content-Length')->withoutHeader('Transfer-Encoding');
            }
        }
        return $response;
    }
}
