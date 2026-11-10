<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use PhpHono\Context;
use PhpHono\Hono;
use PhpHono\Router\OrderedRouter;
use PhpHono\Router\RouteMatch;
use PhpHono\Router\RouterInterface;
use Psr\Http\Message\ResponseInterface;

require dirname(__DIR__) . '/vendor/autoload.php';
$router = new class implements RouterInterface {
    public int $matchingNanoseconds = 0;
    private readonly OrderedRouter $delegate;

    public function __construct()
    {
        $this->delegate = new OrderedRouter();
    }

    /** @param array<string, mixed> $metadata */
    public function add(string $method, string $path, callable $handler, array $metadata = []): void
    {
        $this->delegate->add($method, $path, $handler, $metadata);
    }

    /** @return list<RouteMatch> */
    public function match(string $method, string $path): array
    {
        $start = hrtime(true);
        try {
            return $this->delegate->match($method, $path);
        } finally {
            $this->matchingNanoseconds += hrtime(true) - $start;
        }
    }
};
$app = new Hono(router: $router);
for ($index = 0; $index < 1000; ++$index) {
    $app->get('/route/' . $index . '/:id', static fn(Context $c): ResponseInterface => $c->text('ok'));
}
$request = new Psr17Factory()->createServerRequest('GET', 'http://localhost/route/999/42');
for ($warm = 0; $warm < 20; ++$warm) {
    $app->handle($request);
}
$router->matchingNanoseconds = 0;
$start = hrtime(true);
for ($index = 0; $index < 2000; ++$index) {
    if ((string) $app->handle($request)->getBody() !== 'ok') {
        throw new RuntimeException('Profile result mismatch');
    }
}
$total = hrtime(true) - $start;
echo
    json_encode([
        'php' => PHP_VERSION,
        'routes' => 1000,
        'requests' => 2000,
        'total_ns' => $total,
        'routing_ns' => $router->matchingNanoseconds,
        'routing_fraction' => $router->matchingNanoseconds / $total,
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
    "\n";
