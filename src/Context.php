<?php

declare(strict_types=1);

namespace PhpHono;

use Closure;
use InvalidArgumentException;
use LogicException;
use PhpHono\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * Request state and the response helpers form the public Hono context API.
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 */
final class Context
{
    /** @var array<string, mixed> */
    private array $variables = [];
    /** @var array<string, list<string>> */
    private array $headers = [];
    private ?ResponseInterface $result = null;
    private int $statusCode = 200;
    public ?Throwable $error = null;
    /** @var Closure(self): ResponseInterface */
    private readonly Closure $notFoundHandler;

    /**
     * @param array<string, mixed> $env
     * @param (callable(self): ResponseInterface)|null $notFound
     */
    public function __construct(
        public HonoRequest $req,
        public readonly array $env = [],
        private readonly ResponseFactory $factory = new ResponseFactory(),
        ?callable $notFound = null,
    ) {
        $this->notFoundHandler = $notFound === null
            ? static fn(self $c): ResponseInterface => $c->text('404 Not Found', 404)
            : Closure::fromCallable($notFound);
    }

    public function set(string $key, mixed $value): void
    {
        $this->variables[$key] = $value;
    }

    public function get(string $key): mixed
    {
        return $this->variables[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->variables);
    }

    /**
     * @template T of object
     * @param class-string<T> $type
     * @return T
     */
    public function extension(string $type): object
    {
        $value = $this->get($type);
        if (!$value instanceof $type) {
            throw new LogicException('Missing or invalid Context extension: ' . $type);
        }
        return $value;
    }

    public function status(int $code): void
    {
        if ($code < 100 || $code > 599) {
            throw new InvalidArgumentException('HTTP status must be between 100 and 599.');
        }
        $this->statusCode = $code;
    }

    /**
     * The public header operation handles append, replace and remove before or after finalization.
     * @param string|list<string>|null $value
     * @mago-expect lint:halstead
     */
    public function header(string $name, string|array|null $value, bool $append = false): void
    {
        ResponseFactory::validateHeader($name, $value ?? []);
        if ($value === null) {
            $this->result = $this->result?->withoutHeader($name);
            unset($this->headers[strtolower($name)]);
            return;
        }
        if ($this->result !== null) {
            $this->result = $append
                ? $this->result->withAddedHeader($name, $value)
                : $this->result->withHeader($name, $value);
            return;
        }
        $name = strtolower($name);
        $values = is_array($value) ? $value : [$value];
        if ($append) {
            $values = array_merge($this->headers[$name] ?? [], $values);
        }
        $this->headers[$name] = $values;
    }

    /** @param array<string, string|list<string>> $headers */
    public function body(
        string|StreamInterface $value = '',
        ?int $status = null,
        array $headers = [],
    ): ResponseInterface {
        return $this->factory->create($value, $status ?? $this->statusCode, $headers);
    }

    /** @param array<string, string|list<string>> $headers */
    public function text(string $value, ?int $status = null, array $headers = []): ResponseInterface
    {
        return $this->body($value, $status, $this->contentType($headers, 'text/plain; charset=UTF-8'));
    }

    /** @param array<string, string|list<string>> $headers */
    public function html(string $value, ?int $status = null, array $headers = []): ResponseInterface
    {
        return $this->body($value, $status, $this->contentType($headers, 'text/html; charset=UTF-8'));
    }

    /** @param array<string, string|list<string>> $headers */
    public function json(mixed $value, ?int $status = null, array $headers = []): ResponseInterface
    {
        $body = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $this->body($body, $status, $this->contentType($headers, 'application/json'));
    }

    public function redirect(string $url, int $status = 302): ResponseInterface
    {
        if ($status < 300 || $status > 399) {
            throw new InvalidArgumentException('Redirect status must be between 300 and 399.');
        }
        return $this->body('', $status, ['Location' => $url]);
    }

    public function notFound(): ResponseInterface
    {
        return ($this->notFoundHandler)($this);
    }

    public function response(): ?ResponseInterface
    {
        return $this->result;
    }

    public function setResponse(ResponseInterface $response): void
    {
        if ($this->result !== null) {
            $bodyHeaders = [
                'content-type',
                'content-length',
                'content-encoding',
                'transfer-encoding',
                'etag',
                'last-modified',
            ];
            foreach ($this->result->getHeaders() as $name => $values) {
                if (!$response->hasHeader($name) && !in_array(strtolower($name), $bodyHeaders, strict: true)) {
                    $response = $response->withHeader($name, $values);
                }
            }
        }
        $this->setResponseExact($response);
    }

    /** @internal PSR middleware must preserve explicit header removals. */
    public function setResponseExact(ResponseInterface $response): void
    {
        if ($this->result === null) {
            foreach ($this->headers as $name => $values) {
                if ($name === 'set-cookie') {
                    $response = $response->withHeader($name, [...$values, ...$response->getHeader($name)]);
                } elseif (!$response->hasHeader($name)) {
                    $response = $response->withHeader($name, $values);
                }
            }
            $this->headers = [];
        }
        $this->result = $response;
    }

    /**
     * @param array<string, string|list<string>> $headers
     * @return array<string, string|list<string>>
     */
    private function contentType(array $headers, string $default): array
    {
        foreach (array_keys($headers) as $name) {
            if (strtolower($name) === 'content-type') {
                return $headers;
            }
        }
        $headers['Content-Type'] = $default;
        return $headers;
    }
}
