<?php

declare(strict_types=1);

use PhpHono\Context;
use PhpHono\Hono;
use Psr\Http\Message\ResponseInterface;

$start = hrtime(true);
require dirname(__DIR__) . '/vendor/autoload.php';
$app = new Hono();
for ($index = 0; $index < 100; ++$index) {
    $app->get('/route/' . $index . '/:id', static fn(Context $c): ResponseInterface => $c->text('ok'));
}
$bootstrap = hrtime(true);
$response = $app->request('/route/99/42');
if ((string) $response->getBody() !== 'ok') {
    throw new RuntimeException('Cold response mismatch');
}
echo
    json_encode([
        'bootstrap_us' => ($bootstrap - $start) / 1000,
        'first_dispatch_us' => (hrtime(true) - $bootstrap) / 1000,
        'peak_bytes' => memory_get_peak_usage(true),
    ], JSON_THROW_ON_ERROR),
    "\n";
