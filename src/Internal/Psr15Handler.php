<?php

declare(strict_types=1);

namespace PhpHono\Internal;

use Closure;
use LogicException;
use PhpHono\Context;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** @internal One continuation per bridge invocation, never shared between requests. */
final class Psr15Handler implements RequestHandlerInterface
{
    private bool $used = false;
    private readonly string $method;
    private readonly string $uri;

    /** @param Closure(): ResponseInterface $next */
    public function __construct(
        private readonly Context $context,
        private readonly Closure $next,
    ) {
        $this->method = $context->req->method();
        $this->uri = (string) $context->req->raw()->getUri();
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->used) {
            throw new LogicException('PSR-15 downstream may only be called once.');
        }
        $this->used = true;
        if ($request->getMethod() !== $this->method || (string) $request->getUri() !== $this->uri) {
            throw new LogicException('Rewrite method/URI in an external PSR-15 pipeline before routing.');
        }
        $previous = $this->context->req;
        try {
            $view = $previous->withRaw($request);
            $request = $request->withBody($view->bodyStore()->replay());
            $this->context->req = $view->withRaw($request);
            return ($this->next)();
        } finally {
            $this->context->req = $previous;
        }
    }
}
