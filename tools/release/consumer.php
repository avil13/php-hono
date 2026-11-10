<?php

declare(strict_types=1);

use PhpHono\Context;
use PhpHono\Hono;
use PhpHono\Middleware\Psr15;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

require __DIR__ . '/vendor/autoload.php';

$app = new Hono();
$app->use('*', Psr15::wrap(new class implements MiddlewareInterface {
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request->withAttribute('consumer', 'standalone'))->withHeader('X-Psr15', 'yes');
    }
}));
$app->get('/hello/:name', static fn(Context $context): ResponseInterface => $context->json([
    'hello' => $context->req->param('name'),
    'consumer' => $context->req->raw()->getAttribute('consumer'),
]));
$response = $app->request('/hello/production');
if (
    $response->getStatusCode() !== 200
    || $response->getHeaderLine('X-Psr15') !== 'yes'
    || (string) $response->getBody() !== '{"hello":"production","consumer":"standalone"}'
) {
    throw new RuntimeException('Standalone consumer routing/JSON/PSR-15 smoke failed.');
}
foreach ([
    'PHPUnit\\Framework\\TestCase',
    'PHPStan\\Analyser\\Analyser',
    'GuzzleHttp\\Psr7\\HttpFactory',
] as $developmentClass) {
    if (class_exists($developmentClass)) {
        throw new RuntimeException('Development dependency installed in standalone consumer: ' . $developmentClass);
    }
}
$packages = Composer\InstalledVersions::getInstalledPackagesByType('library');
echo json_encode(['status' => 'passed', 'php' => PHP_VERSION, 'packages' => $packages], JSON_THROW_ON_ERROR) . "\n";
