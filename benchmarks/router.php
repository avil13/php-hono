<?php

declare(strict_types=1);

use PhpHono\Router\OrderedRouter;

require dirname(__DIR__) . '/vendor/autoload.php';
$iterations = max(10, (int) ($argv[1] ?? 1000));
$results = [];
foreach ([10, 100, 1000] as $count) {
    foreach (['static', 'dynamic', 'miss', 'overlap'] as $scenario) {
        $router = new OrderedRouter();
        for ($index = 0; $index < $count; ++$index) {
            $router->add('GET', '/route/' . $index . ($scenario === 'static' ? '' : '/:id'), static fn(): null => null);
        }
        if ($scenario === 'overlap') {
            $router->add('*', '/route/*', static fn(): null => null);
        }
        $path = $scenario === 'miss' ? '/missing' : '/route/' . ($count - 1) . ($scenario === 'static' ? '' : '/42');
        $expected = match ($scenario) {
            'miss' => 0,
            'overlap' => 2,
            default => 1,
        };
        $samples = [];
        for ($round = 0; $round < 6; ++$round) {
            $started = hrtime(true);
            for ($iteration = 0; $iteration < $iterations; ++$iteration) {
                if (count($router->match('GET', $path)) !== $expected) {
                    throw new RuntimeException('Router result mismatch');
                }
            }
            if ($round > 0) {
                $samples[] = ((hrtime(true) - $started) / $iterations) / 1000;
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
        'os' => PHP_OS_FAMILY,
        'iterations' => $iterations,
        'rounds' => 5,
        'peak_bytes' => memory_get_peak_usage(true),
        'results' => $results,
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
    "\n";
