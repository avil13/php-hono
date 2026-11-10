<?php

declare(strict_types=1);

use PhpHono\Context;
use PhpHono\Helper\Streaming;
use PhpHono\Hono;
use PhpHono\HttpException;
use PhpHono\Middleware\BodyLimit;
use PhpHono\Middleware\RequestId;
use PhpHono\Middleware\SecureHeaders;
use Psr\Http\Message\ResponseInterface;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$app = new Hono();
$app->use('*', new RequestId(), new SecureHeaders());
$app->get('/health', static fn(Context $c): ResponseInterface => $c->json(['ok' => true]));
$app->get('/hello/:name', static fn(Context $c): ResponseInterface => $c->json(['hello' => $c->req->param('name')]));
$app->post('/echo', new BodyLimit(1024), static fn(Context $c): ResponseInterface => $c->json($c->req->json()));
$app->get('/error', static function (): never {
    throw new HttpException(409, 'Example conflict');
});
$app->get('/stream', static fn(Context $c): ResponseInterface => Streaming::streamText($c, static function (): iterable {
    yield "first\n";
    usleep(200_000);
    yield "second\n";
}));
$app->get('/events', static fn(Context $c): ResponseInterface => Streaming::streamSSE($c, static function (): iterable {
    yield ['event' => 'ready', 'data' => 'connected', 'id' => '1'];
}));

return $app;
