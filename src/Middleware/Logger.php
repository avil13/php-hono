<?php

declare(strict_types=1);

namespace PhpHono\Middleware;

use Closure;
use PhpHono\Context;
use Throwable;

final class Logger
{
    /** @var Closure(array{method: string, path: string, status: int, durationMs: float, requestId: ?string}): void */
    private readonly Closure $sink;

    /** @var (Closure(Throwable): void)|null */
    private readonly ?Closure $onFailure;

    /**
     * @param callable(array{method: string, path: string, status: int, durationMs: float, requestId: ?string}): void $sink
     * @param (callable(Throwable): void)|null $onFailure
     */
    public function __construct(callable $sink, ?callable $onFailure = null)
    {
        $this->sink = Closure::fromCallable($sink);
        $this->onFailure = $onFailure === null ? null : Closure::fromCallable($onFailure);
    }

    /**
     * A failed diagnostic callback must not replace the response or downstream exception.
     *
     * @mago-expect lint:no-empty-catch-clause
     */
    public function __invoke(Context $context, callable $next): void
    {
        $started = hrtime(true);
        $method = $context->req->method();
        $path = $context->req->path();

        try {
            $next();
        } finally {
            $id = $context->get('requestId');

            try {
                ($this->sink)([
                    'method' => $method,
                    'path' => $path,
                    'status' => $context->response()?->getStatusCode() ?? 500,
                    'durationMs' => (hrtime(true) - $started) / 1_000_000,
                    'requestId' => is_string($id) ? $id : null,
                ]);
            } catch (Throwable $exception) {
                if ($this->onFailure !== null) {
                    try {
                        ($this->onFailure)($exception);
                    } catch (Throwable) {
                        // Diagnostic failures must not replace the HTTP response either.
                    }
                }
            }
        }
    }
}
