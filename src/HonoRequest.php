<?php

declare(strict_types=1);

namespace PhpHono;

use InvalidArgumentException;
use JsonException;
use LogicException;
use PhpHono\Internal\BodyStore;
use PhpHono\Internal\QueryParser;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The public request facade groups the Hono read helpers and request-local caches.
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 */
final class HonoRequest
{
    private BodyStore $bodyStore;

    /** @var array<array-key, non-empty-list<string>>|null */
    private ?array $queryValues = null;

    private bool $jsonRead = false;

    private mixed $jsonValue = null;

    /** @var array<int, array<array-key, mixed>> */
    private array $forms = [];

    /** @var array<string, mixed> */
    private array $validated = [];

    /** @param array<string, string> $params Already decoded parameters of the current match. */
    public function __construct(
        private ServerRequestInterface $request,
        private array $params = [],
        ?BodyStore $bodyStore = null,
        private ?string $routingPath = null,
    ) {
        self::validateTarget($request);
        $this->bodyStore = $bodyStore ?? new BodyStore($request->getBody());
    }

    public static function validateTarget(ServerRequestInterface $request): void
    {
        $target = $request->getRequestTarget();

        if (preg_match('/[\x00-\x20\x7f]/', $target) === 1) {
            throw new HttpException(400, 'Invalid request target');
        }

        // A PSR URI can normalize an invalid target, so validate both representations.
        QueryParser::decode($target);
        QueryParser::decode($request->getUri()->getPath());
        QueryParser::decode($request->getUri()->getQuery());
    }

    public function raw(): ServerRequestInterface
    {
        return $this->request;
    }

    public function method(): string
    {
        return $this->request->getMethod();
    }

    public function path(): string
    {
        $path = $this->request->getUri()->getPath();

        return $this->routingPath ?? ($path === '' ? '/' : $path);
    }

    public function url(): string
    {
        return (string) $this->request->getUri();
    }

    /** @return ($name is null ? array<string, string> : string|null) */
    public function param(?string $name = null): string|array|null
    {
        return $name === null ? $this->params : $this->params[$name] ?? null;
    }

    /**
     * @param array<string, string> $params Already decoded parameters of the current match.
     *
     * @internal
     */
    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    /** @return ($name is null ? array<array-key, string> : string|null) */
    public function query(?string $name = null): string|array|null
    {
        $values = $this->queryValues();

        if ($name !== null) {
            return $values[$name][0] ?? null;
        }

        $result = [];

        foreach ($values as $key => $items) {
            $result[$key] = $items[0];
        }

        return $result;
    }

    /** @return ($name is null ? array<array-key, non-empty-list<string>> : non-empty-list<string>|null) */
    public function queries(?string $name = null): ?array
    {
        $values = $this->queryValues();

        return $name === null ? $values : $values[$name] ?? null;
    }

    /** @return ($name is null ? array<string, string> : string|null) */
    public function header(?string $name = null): string|array|null
    {
        if ($name !== null) {
            return $this->request->hasHeader($name) ? $this->request->getHeaderLine($name) : null;
        }

        $result = [];

        foreach ($this->request->getHeaders() as $key => $values) {
            $result[strtolower($key)] = implode(', ', $values);
        }

        return $result;
    }

    public function text(): string
    {
        return $this->bodyStore->bytes();
    }

    public function json(): mixed
    {
        $body = $this->text();

        if (!$this->jsonRead) {
            try {
                $this->jsonValue = json_decode(
                    $body,
                    associative: false,
                    depth: 512,
                    flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
                );
            } catch (JsonException $exception) {
                throw new HttpException(400, 'Invalid JSON', null, $exception);
            }

            $this->jsonRead = true;
        }

        return $this->jsonValue;
    }

    /** @return array<array-key, mixed> */
    public function parseBody(bool $all = false): array
    {
        $key = (int) $all;
        $mediaType = strtolower(trim(explode(';', $this->request->getHeaderLine('Content-Type'), limit: 2)[0]));
        $encoded = $mediaType === 'application/x-www-form-urlencoded' ? $this->text() : '';

        if (array_key_exists($key, $this->forms)) {
            return $this->forms[$key];
        }

        if ($mediaType === 'multipart/form-data') {
            if ($all) {
                throw new InvalidArgumentException('Multipart parseBody(all: true) is not supported');
            }

            if ($this->request->getAttribute('php_hono.multipart_parsed') !== true) {
                throw new HttpException(415, 'Multipart body requires a configured parser');
            }

            $parsed = $this->request->getParsedBody();

            return $this->forms[$key] = is_object($parsed) ? get_object_vars($parsed) : $parsed ?? [];
        }

        if ($mediaType !== 'application/x-www-form-urlencoded') {
            return $this->forms[$key] = [];
        }

        $result = [];

        foreach (QueryParser::parse($encoded) as $name => $values) {
            $result[$name] = str_ends_with((string) $name, '[]') || $all && count($values) > 1
                ? $values
                : $values[array_key_last($values)];
        }

        return $this->forms[$key] = $result;
    }

    /**
     * The local annotation narrows PSR-7's untyped nested array without copying the tree.
     *
     * @return array<array-key, UploadedFileInterface|array<mixed>>
     * @mago-expect lint:inline-variable-return
     */
    public function uploadedFiles(): array
    {
        /** @var array<array-key, UploadedFileInterface|array<mixed>> $files PSR-7 guarantees the uploaded file tree. */
        $files = $this->request->getUploadedFiles();
        return $files;
    }

    public function valid(string $target): mixed
    {
        if (!array_key_exists($target, $this->validated)) {
            throw new LogicException('No validated data for target: ' . $target);
        }

        return $this->validated[$target];
    }

    /** @internal */
    public function setValidated(string $target, mixed $value): void
    {
        $this->validated[$target] = $value;
    }

    /** @internal */
    public function bodyStore(): BodyStore
    {
        return $this->bodyStore;
    }

    /** @internal */
    public function withRaw(ServerRequestInterface $request): self
    {
        self::validateTarget($request);
        $view = clone $this;
        $view->request = $request;
        $view->forms = [];

        if (!$this->bodyStore->owns($request->getBody())) {
            $view->bodyStore = new BodyStore($request->getBody(), $this->bodyStore->limit());
            $view->jsonRead = false;
            $view->jsonValue = null;
            unset($view->validated['json'], $view->validated['form']);
        }

        if ($request->getUri()->getQuery() !== $this->request->getUri()->getQuery()) {
            $view->queryValues = null;
            unset($view->validated['query']);
        }

        if ($request->getHeaders() !== $this->request->getHeaders()) {
            unset($view->validated['header']);
        }
        if ($request->getHeaderLine('Cookie') !== $this->request->getHeaderLine('Cookie')) {
            unset($view->validated['cookie']);
        }
        if ($request->getHeaderLine('Content-Type') !== $this->request->getHeaderLine('Content-Type')) {
            unset($view->validated['json'], $view->validated['form']);
        }
        if (
            $request->getParsedBody() !== $this->request->getParsedBody()
            || $request->getAttribute('php_hono.multipart_parsed') !== $this->request->getAttribute(
                'php_hono.multipart_parsed',
            )
        ) {
            unset($view->validated['form']);
        }

        return $view;
    }

    /** @return array<array-key, non-empty-list<string>> */
    private function queryValues(): array
    {
        return $this->queryValues ??= QueryParser::parse($this->request->getUri()->getQuery());
    }
}
