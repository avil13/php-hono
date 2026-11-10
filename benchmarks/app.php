<?php

declare(strict_types=1);

use PhpHono\Context;
use PhpHono\Hono;
use PhpHono\Middleware\RequestId;
use PhpHono\Middleware\SecureHeaders;
use Psr\Http\Message\ResponseInterface;

require dirname(__DIR__) . '/vendor/autoload.php';
$app = new Hono();
$app->use('*', new RequestId(), new SecureHeaders());
for ($index = 0; $index < 100; ++$index) {
    $app->get('/route/' . $index . '/:id', static fn(Context $c): ResponseInterface => $c->json([
        'id' => $c->req->param('id'),
    ]));
}
return $app;
