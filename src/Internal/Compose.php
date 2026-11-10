<?php

declare(strict_types=1);

namespace PhpHono\Internal;

use Closure;
use LogicException;
use PhpHono\Context;
use PhpHono\HttpException;
use PhpHono\Router\RouteMatch;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * One instance per request; finalization and error branches must share the same boundary.
 *
 * @internal
 * @mago-expect lint:cyclomatic-complexity
 */
final class Compose
{
    /**
     * @param list<RouteMatch> $matches
     * @param (Closure(Throwable, Context): ResponseInterface)|null $onError
     */
    public function __construct(
        private readonly Context $context,
        private readonly array $matches,
        private readonly ?Closure $onError = null,
    ) {}

    public function run(int $index = 0): ResponseInterface
    {
        $match = $this->matches[$index] ?? null;
        $request = $this->context->req;
        $previousParams = $request->param();
        try {
            if ($match === null) {
                if ($this->context->response() === null) {
                    $this->context->setResponse($this->context->notFound());
                }
            } else {
                $request->setParams($match->params);
                $called = false;
                $next = function () use ($index, &$called): ResponseInterface {
                    if ($called) {
                        throw new LogicException('next() may only be called once.');
                    }
                    $called = true;
                    return $this->run($index + 1);
                };
                $response = ($match->handler)($this->context, $next);
                if ($response instanceof ResponseInterface) {
                    if ($this->context->response() === null) {
                        $this->context->setResponse($response);
                    }
                } elseif ($response !== null) {
                    throw new LogicException('A handler must return a ResponseInterface or null.');
                }
                if ($this->context->response() === null) {
                    throw new LogicException('Handler stopped without a response or next().');
                }
            }
        } catch (Throwable $error) {
            $handler = $match?->metadata['onError'] ?? $this->onError;
            $this->handleError($error, $handler instanceof Closure ? $handler : $this->onError);
        } finally {
            $request->setParams($previousParams);
        }
        return $this->context->response() ?? throw new LogicException('Response was not finalized.');
    }

    /** @param (Closure(Throwable, Context): ResponseInterface)|null $handler */
    public function handleError(Throwable $error, ?Closure $handler = null): ResponseInterface
    {
        $this->context->error = $error;
        try {
            $response = $handler !== null ? $handler($error, $this->context) : $this->defaultError($error);
            $this->context->setResponse($response);
        } catch (Throwable $fallbackError) {
            $this->context->error = $fallbackError;
            $this->context->setResponse($this->context->text('Internal Server Error', 500));
        }
        return $this->context->response() ?? throw new LogicException('Error response was not finalized.');
    }

    private function defaultError(Throwable $error): ResponseInterface
    {
        if ($error instanceof HttpException) {
            return (
                $error->response?->withStatus($error->status) ?? $this->context->text(
                    $error->getMessage() !== '' ? $error->getMessage() : 'HTTP Error',
                    $error->status,
                )
            );
        }
        return $this->context->text('Internal Server Error', 500);
    }
}
