<?php

declare(strict_types=1);

namespace PhpHono\Middleware;

use PhpHono\Context;
use PhpHono\Hono;

final readonly class MethodNotAllowed
{
    public function __construct(
        private Hono $app,
    ) {}

    /** @param callable(): void $next */
    public function __invoke(Context $context, callable $next): void
    {
        $next();
        if ($context->response()?->getStatusCode() !== 404 || $context->error !== null) {
            return;
        }
        $methods = $this->app->allowedMethods($context->req->path());
        if ($methods === [] || in_array(strtoupper($context->req->method()), $methods, strict: true)) {
            return;
        }

        $context->setResponse($context->text('Method Not Allowed', 405, ['Allow' => implode(', ', $methods)]));
    }
}
