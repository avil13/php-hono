<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use PhpHono\Context;
use PhpHono\Hono;
use Psr\Http\Message\ResponseInterface;

require dirname(__DIR__) . '/vendor/autoload.php';
$iterations = max(10, (int) ($argv[1] ?? 1000));
$results = [];
foreach ([10, 100, 1000] as $count) {
    foreach (['static', 'dynamic', 'miss', 'overlap'] as $scenario) {
        $app = new Hono();
        if ($scenario === 'overlap') {
            $app->get('/route/:index/:id', static function (Context $c, callable $next): void {
                $next();
            });
        }
        for ($index = 0; $index < $count; ++$index) {
            $app->get(
                '/route/' . $index . ($scenario === 'static' ? '' : '/:id'),
                static fn(Context $c): ResponseInterface => $c->text('ok'),
            );
        }
        $path = $scenario === 'miss' ? '/missing' : '/route/' . ($count - 1) . ($scenario === 'static' ? '' : '/42');
        $request = new Psr17Factory()->createServerRequest('GET', 'http://localhost' . $path);
        $samples = [];
        for ($round = 0; $round < 6; ++$round) {
            $start = hrtime(true);
            for ($iteration = 0; $iteration < $iterations; ++$iteration) {
                $response = $app->handle($request);
                if (
                    $response->getStatusCode() !== ($scenario === 'miss' ? 404 : 200)
                    || (string) $response->getBody() !== ($scenario === 'miss' ? '404 Not Found' : 'ok')
                ) {
                    throw new RuntimeException('Dispatch scenario mismatch');
                }
            }
            if ($round > 0) {
                $samples[] = ((hrtime(true) - $start) / $iterations) / 1000;
            }
        }
        sort($samples);
        $results[] = [
            'routes' => $count,
            'scenario' => $scenario,
            'median_us' => $samples[2],
            'min_us' => $samples[0],
            'max_us' => $samples[4],
        ];
    }
}
echo
    json_encode([
        'php' => PHP_VERSION,
        'iterations' => $iterations,
        'rounds' => 5,
        'peak_bytes' => memory_get_peak_usage(true),
        'results' => $results,
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
    "\n";
