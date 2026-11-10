<?php

declare(strict_types=1);

namespace PhpHono\Middleware;

use Closure;
use InvalidArgumentException;
use PhpHono\Context;
use PhpHono\Http\ResponseFactory;
use PhpHono\HttpException;
use Psr\Http\Message\ResponseInterface;

/**
 * Origin resolution and preflight handling are one configurable CORS policy.
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class Cors
{
    /** @var Closure(string, Context): ?string */
    private readonly Closure $originResolver;

    private readonly bool $wildcard;

    /**
     * Named configuration keeps the security policy explicit.
     *
     * @mago-expect lint:excessive-parameter-list
     * @param string|list<string>|(callable(string, Context): ?string) $origin
     * @param list<string> $allowMethods
     * @param list<string> $allowHeaders Empty means reflect requested header names on preflight.
     * @param list<string> $exposeHeaders
     */
    public function __construct(
        string|array|callable $origin = '*',
        private readonly array $allowMethods = ['GET', 'HEAD', 'PUT', 'POST', 'DELETE', 'PATCH', 'QUERY'],
        private readonly array $allowHeaders = [],
        private readonly bool $credentials = false,
        private readonly array $exposeHeaders = [],
        private readonly ?int $maxAge = null,
    ) {
        $this->wildcard = $origin === '*' || is_array($origin) && in_array('*', $origin, strict: true);

        if ($credentials && $this->wildcard) {
            throw new InvalidArgumentException('Wildcard CORS origin cannot be combined with credentials');
        }

        if ($maxAge !== null && $maxAge < 0) {
            throw new InvalidArgumentException('CORS maxAge must not be negative');
        }

        foreach ([...$allowMethods, ...$allowHeaders, ...$exposeHeaders] as $token) {
            ResponseFactory::validateHeader($token, '');
        }

        if (is_string($origin)) {
            ResponseFactory::validateHeader('Access-Control-Allow-Origin', $origin);
            $this->originResolver = static fn(string $incoming, Context $context): ?string => $origin === '*'
                || $origin === $incoming
                    ? $origin
                    : null;
        } elseif (is_callable($origin)) {
            /** @var Closure(string, Context): ?string $resolver */
            $resolver = Closure::fromCallable($origin);
            $this->originResolver = $resolver;
        } else {
            /** @var list<string> $origin */
            foreach ($origin as $allowed) {
                ResponseFactory::validateHeader('Access-Control-Allow-Origin', $allowed);
            }

            $wildcard = $this->wildcard;
            $this->originResolver = static function (string $incoming, Context $context) use (
                $wildcard,
                $origin,
            ): ?string {
                if ($wildcard) {
                    return '*';
                }
                return in_array($incoming, $origin, strict: true) ? $incoming : null;
            };
        }
    }

    public function __invoke(Context $context, callable $next): ?ResponseInterface
    {
        $origin = $context->req->raw()->getHeaderLine('Origin');
        $allowed = $origin === '' ? null : ($this->originResolver)($origin, $context);

        if ($allowed === '') {
            $allowed = null;
        }

        if ($allowed === '*' && $this->credentials) {
            throw new InvalidArgumentException('Wildcard CORS origin cannot be combined with credentials');
        }

        if ($allowed !== null) {
            ResponseFactory::validateHeader('Access-Control-Allow-Origin', $allowed);
        }

        $preflight =
            strtoupper($context->req->method()) === 'OPTIONS'
            && $origin !== ''
            && $context->req->raw()->getHeaderLine('Access-Control-Request-Method') !== '';

        if ($preflight) {
            $context->setResponse($context->body('', 204));
            $this->apply($context, $allowed, true);
            $context->header('Content-Type', null);
            $context->header('Content-Length', null);

            return $context->response();
        }

        $next();
        $this->apply($context, $allowed, false);

        return null;
    }

    private function apply(Context $context, ?string $allowed, bool $preflight): void
    {
        foreach ([
            'Access-Control-Allow-Origin',
            'Access-Control-Allow-Credentials',
            'Access-Control-Expose-Headers',
            'Access-Control-Allow-Methods',
            'Access-Control-Allow-Headers',
            'Access-Control-Max-Age',
        ] as $header) {
            $context->header($header, null);
        }

        if (!$this->wildcard) {
            self::vary($context, 'Origin');
        }

        if ($allowed === null) {
            return;
        }

        $context->header('Access-Control-Allow-Origin', $allowed);

        if ($this->credentials) {
            $context->header('Access-Control-Allow-Credentials', 'true');
        }

        if ($this->exposeHeaders !== []) {
            $context->header('Access-Control-Expose-Headers', implode(',', $this->exposeHeaders));
        }

        if (!$preflight) {
            return;
        }

        if ($this->allowMethods !== []) {
            $context->header('Access-Control-Allow-Methods', implode(',', $this->allowMethods));
        }

        $headers = $this->allowHeaders;

        if ($headers === []) {
            foreach (explode(',', $context->req->raw()->getHeaderLine('Access-Control-Request-Headers')) as $header) {
                $header = trim($header);

                if ($header === '') {
                    continue;
                }

                try {
                    ResponseFactory::validateHeader($header, '');
                } catch (InvalidArgumentException $exception) {
                    throw new HttpException(400, 'Invalid preflight header name', null, $exception);
                }

                $headers[] = $header;
            }

            self::vary($context, 'Access-Control-Request-Headers');
        }

        if ($headers !== []) {
            $context->header('Access-Control-Allow-Headers', implode(',', $headers));
        }

        if ($this->maxAge !== null) {
            $context->header('Access-Control-Max-Age', (string) $this->maxAge);
        }
    }

    private static function vary(Context $context, string $name): void
    {
        $values = [];

        foreach (explode(',', $context->response()?->getHeaderLine('Vary') ?? '') as $value) {
            $value = trim($value);

            if ($value === '*') {
                return;
            }

            if ($value !== '') {
                $values[strtolower($value)] = $value;
            }
        }

        $values[strtolower($name)] ??= $name;
        $context->header('Vary', implode(', ', $values));
    }
}
