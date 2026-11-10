<?php

declare(strict_types=1);

namespace PhpHono\Middleware;

use PhpHono\Context;
use Psr\Http\Message\ResponseInterface;

final readonly class TrailingSlash
{
    public function __construct(
        private bool $append = false,
        private bool $alwaysRedirect = false,
    ) {}

    /** @param callable(): void $next */
    public function __invoke(Context $context, callable $next): ?ResponseInterface
    {
        $path = $context->req->path();
        $needsRedirect = $this->append ? !str_ends_with($path, '/') : $path !== '/' && str_ends_with($path, '/');
        $safe = in_array(strtoupper($context->req->method()), ['GET', 'HEAD'], strict: true);
        if ($this->alwaysRedirect && $needsRedirect && $safe) {
            return $this->redirect($context, $path);
        }
        $next();
        if (
            !$this->alwaysRedirect
            && $needsRedirect
            && $safe
            && $context->error === null
            && $context->response()?->getStatusCode() === 404
        ) {
            $context->setResponse($this->redirect($context, $path));
        }

        return null;
    }

    private function redirect(Context $context, string $path): ResponseInterface
    {
        $target = $this->append ? $path . '/' : substr($path, offset: 0, length: -1);
        $uri = $context->req->raw()->getUri()->withPath($target);

        return $context->redirect((string) $uri, 301);
    }
}
