<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use PhpHono\Benchmarks\State;
use PhpHono\Context;
use PhpHono\Hono;
use PhpHono\Middleware\Psr15;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/State.php';

$iterations = max(10, (int) ($argv[1] ?? 1000));
$factory = new Psr17Factory();
$passthrough = new class implements MiddlewareInterface {
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request);
    }
};
$results = [];
foreach ([10, 100, 1000] as $routeCount) {
    foreach ([0, 1, 5, 10] as $middlewareCount) {
        foreach (['native', 'psr', 'extension'] as $mode) {
            $started = hrtime(true);
            $app = new Hono();
            if ($mode === 'extension') {
                $app->use('*', static function (Context $c, callable $next): void {
                    $c->set(State::class, new State('ok'));
                    $next();
                });
            }
            for ($index = 0; $index < $middlewareCount; $index++) {
                $app->use(
                    '*',
                    $mode === 'psr'
                        ? Psr15::wrap($passthrough)
                        : static function (Context $c, callable $next): void {
                            $next();
                        },
                );
            }
            for ($index = 0; $index < $routeCount; $index++) {
                $app->get('/route/' . $index . '/:id', static fn(Context $c): ResponseInterface => $c->text(
                    $mode === 'extension' ? $c->extension(State::class)->value : 'ok',
                ));
            }
            $bootstrap = hrtime(true) - $started;
            $request = $factory->createServerRequest('GET', 'http://localhost/route/' . ($routeCount - 1) . '/42');
            for ($warm = 0; $warm < 20; $warm++) {
                $app->handle($request);
            }
            $samples = [];
            for ($round = 0; $round < 5; $round++) {
                $started = hrtime(true);
                for ($iteration = 0; $iteration < $iterations; $iteration++) {
                    $response = $app->handle($request);
                    if ($response->getStatusCode() !== 200 || (string) $response->getBody() !== 'ok') {
                        throw new RuntimeException('Benchmark response mismatch');
                    }
                }
                $samples[] = ((hrtime(true) - $started) / $iterations) / 1000;
            }
            sort($samples);
            $results[] = [
                'routes' => $routeCount,
                'middleware' => $middlewareCount,
                'mode' => $mode,
                'bootstrap_us' => $bootstrap / 1000,
                'median_us' => $samples[2],
                'min_us' => $samples[0],
                'max_us' => $samples[4],
            ];
        }
    }
}
echo
    json_encode([
        'php' => PHP_VERSION,
        'os' => PHP_OS_FAMILY,
        'opcache_cli' => ini_get('opcache.enable_cli'),
        'jit' => ini_get('opcache.jit'),
        'iterations' => $iterations,
        'rounds' => 5,
        'peak_memory_bytes' => memory_get_peak_usage(true),
        'results' => $results,
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
    "\n";
