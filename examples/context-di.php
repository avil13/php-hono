<?php

declare(strict_types=1);

namespace PhpHono\Examples\ContextDi;

use LogicException;
use PhpHono\Context;
use PhpHono\Hono;
use PhpHono\Middleware\Psr15;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/ContextDi/TenantContext.php';
require_once __DIR__ . '/ContextDi/RequestMetadata.php';
require_once __DIR__ . '/ContextDi/AttachTenant.php';
require_once __DIR__ . '/ContextDi/HelloTenant.php';

$app = new Hono();
$app->use('*', new AttachTenant(static fn(): TenantContext => new TenantContext('example')));
$app->use('*', Psr15::wrap(new class implements MiddlewareInterface {
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $metadata = new RequestMetadata(bin2hex(random_bytes(16)));
        return $handler->handle($request->withAttribute(RequestMetadata::class, $metadata));
    }
}));
$app->use('*', static function (Context $context, callable $next): void {
    // Mapping is explicit; removing an attribute must never leave an earlier extension active.
    $context->set(RequestMetadata::class, null);
    $metadata = $context->req->raw()->getAttribute(RequestMetadata::class);
    if (!$metadata instanceof RequestMetadata) {
        throw new LogicException('Expected request metadata from the PSR middleware.');
    }
    $context->set(RequestMetadata::class, $metadata);
    $next();
});
$app->get('/whoami', new HelloTenant('Hello, tenant '));

return $app;
