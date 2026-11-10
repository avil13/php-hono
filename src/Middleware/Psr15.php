<?php

declare(strict_types=1);

namespace PhpHono\Middleware;

use Closure;
use PhpHono\Context;
use PhpHono\Internal\Psr15Handler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;

final class Psr15
{
    /** @return Closure(Context, callable(): ResponseInterface): ResponseInterface */
    public static function wrap(MiddlewareInterface $middleware): Closure
    {
        return static function (Context $context, callable $next) use ($middleware): ResponseInterface {
            /** @var callable(): ResponseInterface $next */
            $original = $context->req;
            $request = $original->raw()->withBody($original->bodyStore()->replay());
            $context->req = $original->withRaw($request);
            try {
                $response = $middleware->process($request, new Psr15Handler($context, Closure::fromCallable($next)));
                $context->setResponseExact($response);
                return $response;
            } finally {
                $context->req = $original;
            }
        };
    }
}
