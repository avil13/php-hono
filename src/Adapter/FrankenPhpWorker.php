<?php

declare(strict_types=1);

namespace PhpHono\Adapter;

use InvalidArgumentException;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

final class FrankenPhpWorker
{
    /** @param (callable(): void)|null $cleanup Runs after each request, including failed requests. */
    public static function run(RequestHandlerInterface $app, int $maxRequests = 500, ?callable $cleanup = null): void
    {
        if ($maxRequests < 1) {
            throw new InvalidArgumentException('Worker request limit must be positive.');
        }
        if (!function_exists('frankenphp_handle_request')) {
            throw new RuntimeException('FrankenPhpWorker requires FrankenPHP worker mode.');
        }
        $handler = static function () use ($app, $cleanup): void {
            try {
                Sapi::run($app);
            } finally {
                try {
                    if ($cleanup !== null) {
                        $cleanup();
                    }
                } finally {
                    // Release request/exception cycles while native input buffers are still owned by this request.
                    gc_collect_cycles();
                }
            }
        };
        for ($requests = 0; $requests < $maxRequests; ++$requests) {
            $keepRunning = frankenphp_handle_request($handler);
            if (!$keepRunning) {
                break;
            }
        }
    }
}
