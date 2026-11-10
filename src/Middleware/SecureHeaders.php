<?php

declare(strict_types=1);

namespace PhpHono\Middleware;

use PhpHono\Context;
use PhpHono\Http\ResponseFactory;

final readonly class SecureHeaders
{
    /** @var array<string, string> */
    private const array DEFAULTS = [
        'Cross-Origin-Resource-Policy' => 'same-origin',
        'Cross-Origin-Opener-Policy' => 'same-origin',
        'Origin-Agent-Cluster' => '?1',
        'Referrer-Policy' => 'no-referrer',
        'Strict-Transport-Security' => 'max-age=15552000; includeSubDomains',
        'X-Content-Type-Options' => 'nosniff',
        'X-DNS-Prefetch-Control' => 'off',
        'X-Download-Options' => 'noopen',
        'X-Frame-Options' => 'SAMEORIGIN',
        'X-Permitted-Cross-Domain-Policies' => 'none',
        'X-XSS-Protection' => '0',
    ];

    /** @var array<string, string|null> */
    private array $headers;

    /** @param array<string, string|null> $overrides Null disables a default header. CSP is configured explicitly. */
    public function __construct(
        array $overrides = [],
        private bool $removePoweredBy = true,
    ) {
        $headers = array_change_key_case(self::DEFAULTS, CASE_LOWER);

        foreach ($overrides as $name => $value) {
            ResponseFactory::validateHeader($name, $value ?? []);
            $headers[strtolower($name)] = $value;
        }

        $this->headers = $headers;
    }

    public function __invoke(Context $context, callable $next): void
    {
        $next();

        foreach ($this->headers as $name => $value) {
            if ($value === null) {
                continue;
            }

            $context->header($name, $value);
        }

        if ($this->removePoweredBy) {
            $context->header('X-Powered-By', null);
        }
    }
}
