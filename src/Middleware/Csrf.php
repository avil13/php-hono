<?php

declare(strict_types=1);

namespace PhpHono\Middleware;

use InvalidArgumentException;
use PhpHono\Context;
use Psr\Http\Message\ResponseInterface;

/**
 * The origin and Fetch Metadata checks jointly implement one CSRF policy.
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class Csrf
{
    /** @var list<string> */
    private array $origins;

    /**
     * @param list<string> $origins Empty means same request origin.
     * @param list<string> $fetchSites
     */
    public function __construct(
        array $origins = [],
        private array $fetchSites = ['same-origin'],
    ) {
        $normalized = [];
        foreach ($origins as $origin) {
            $value = self::origin($origin);
            if ($value === null) {
                throw new InvalidArgumentException(
                    'CSRF origins must be HTTP(S) origins without credentials or paths.',
                );
            }
            $normalized[] = $value;
        }
        foreach ($fetchSites as $site) {
            if (!in_array($site, ['same-origin', 'same-site', 'none', 'cross-site'], strict: true)) {
                throw new InvalidArgumentException('Invalid Sec-Fetch-Site option.');
            }
        }
        $this->origins = $normalized;
    }

    /** @param callable(): void $next */
    public function __invoke(Context $context, callable $next): ?ResponseInterface
    {
        $type = strtolower(trim(explode(';', $context->req->header('Content-Type') ?? 'text/plain', limit: 2)[0]));
        if (
            in_array(strtoupper($context->req->method()), ['GET', 'HEAD', 'OPTIONS'], strict: true)
            || !in_array(
                $type,
                ['application/x-www-form-urlencoded', 'multipart/form-data', 'text/plain', 'text/ping'],
                strict: true,
            )
        ) {
            $next();
            return null;
        }

        $site = $context->req->header('Sec-Fetch-Site');
        $origin = self::origin($context->req->header('Origin') ?? '');
        $uri = $context->req->raw()->getUri();
        $requestOrigin = self::origin($uri->getScheme() . '://' . $uri->getAuthority());
        $allowedOrigin =
            $origin !== null
            && ($this->origins === [] ? $origin === $requestOrigin : in_array($origin, $this->origins, strict: true));
        if ($site !== null && in_array($site, $this->fetchSites, strict: true) || $allowedOrigin) {
            $next();
            return null;
        }

        return $context->text('Forbidden', 403);
    }

    private static function origin(string $origin): ?string
    {
        if ($origin === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $origin) === 1) {
            return null;
        }
        $parts = parse_url($origin);
        if (
            $parts === false
            || !array_key_exists('scheme', $parts)
            || !array_key_exists('host', $parts)
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)
            || ($parts['path'] ?? '') !== '' && $parts['path'] !== '/'
        ) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return $scheme . '://' . strtolower($parts['host']) . ':' . $port;
    }
}
