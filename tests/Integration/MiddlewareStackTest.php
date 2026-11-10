<?php

declare(strict_types=1);

namespace PhpHono\Tests\Integration;

use Nyholm\Psr7\Factory\Psr17Factory;
use PhpHono\Context;
use PhpHono\Hono;
use PhpHono\Middleware\BearerAuth;
use PhpHono\Middleware\BodyLimit;
use PhpHono\Middleware\Cors;
use PhpHono\Middleware\Csrf;
use PhpHono\Middleware\Psr15;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class MiddlewareStackTest extends TestCase
{
    public function testCorsWrapsAuthenticationAndCsrfOnPreflightRejectionAndSuccess(): void
    {
        $calls = 0;
        $app = new Hono();
        $app->use(
            '*',
            new Cors(
                origin: ['https://app.example.test', 'https://other.example.test'],
                allowMethods: ['POST'],
                allowHeaders: ['Authorization', 'Content-Type'],
                credentials: true,
            ),
        );
        $app->use('*', BearerAuth::token('valid-token'));
        $app->use('*', new Csrf(origins: ['https://app.example.test'], fetchSites: []));
        $app->post('/submit', static function (Context $context) use (&$calls): ResponseInterface {
            $calls++;
            return $context->text('created', 201);
        });

        $preflight = $app->request('/submit', [
            'method' => 'OPTIONS',
            'headers' => [
                'Origin' => 'https://app.example.test',
                'Access-Control-Request-Method' => 'POST',
                'Access-Control-Request-Headers' => 'Authorization, Content-Type',
            ],
        ]);
        self::assertSame(204, $preflight->getStatusCode());
        self::assertSame('POST', $preflight->getHeaderLine('Access-Control-Allow-Methods'));
        self::assertSame('https://app.example.test', $preflight->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame(0, $calls);

        $unauthorized = $app->request('/submit', [
            'method' => 'POST',
            'headers' => [
                'Origin' => 'https://app.example.test',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
        ]);
        self::assertSame(401, $unauthorized->getStatusCode());
        self::assertSame('Bearer realm="Restricted"', $unauthorized->getHeaderLine('WWW-Authenticate'));
        self::assertSame('https://app.example.test', $unauthorized->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('true', $unauthorized->getHeaderLine('Access-Control-Allow-Credentials'));
        self::assertSame(0, $calls);

        $forbidden = $app->request('/submit', [
            'method' => 'POST',
            'headers' => [
                'Authorization' => 'Bearer valid-token',
                'Origin' => 'https://other.example.test',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
        ]);
        self::assertSame(403, $forbidden->getStatusCode());
        self::assertSame('https://other.example.test', $forbidden->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame(0, $calls);

        $accepted = $app->request('/submit', [
            'method' => 'POST',
            'headers' => [
                'Authorization' => 'Bearer valid-token',
                'Origin' => 'https://app.example.test',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => 'value=one',
        ]);
        self::assertSame(201, $accepted->getStatusCode());
        self::assertSame('created', (string) $accepted->getBody());
        self::assertSame('https://app.example.test', $accepted->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame(1, $calls);
    }

    public function testBodyLimitPrecedesPsrReadsAndKeepsNonSeekableInputReplayable(): void
    {
        $reader = new class implements MiddlewareInterface {
            public int $calls = 0;

            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface {
                $this->calls++;
                $body = $request->getBody()->getContents();
                $request->getBody()->rewind();
                TestCase::assertSame($body, $request->getBody()->getContents());
                return $handler->handle($request->withAttribute('psr-body', $body));
            }
        };
        $calls = 0;
        $app = new Hono();
        $app->use('*', new BodyLimit(maxBytes: 5), Psr15::wrap($reader));
        $app->post('/', static function (Context $context) use (&$calls): ResponseInterface {
            $calls++;
            $first = $context->req->text();
            return $context->json([
                'psr' => $context->req->raw()->getAttribute('psr-body'),
                'first' => $first,
                'second' => $context->req->text(),
                'raw' => $context->req->raw()->getBody()->getContents(),
            ]);
        });

        $oversized = self::nonSeekableRequest('longer');
        try {
            self::assertSame(413, $app->handle($oversized)->getStatusCode());
            self::assertSame(0, $reader->calls);
            self::assertSame(0, $calls);
        } finally {
            $oversized->getBody()->close();
        }

        $request = self::nonSeekableRequest('hello');
        try {
            $response = $app->handle($request);
            self::assertSame(200, $response->getStatusCode());
            self::assertSame(
                '{"psr":"hello","first":"hello","second":"hello","raw":"hello"}',
                (string) $response->getBody(),
            );
            self::assertSame(1, $reader->calls);
            self::assertSame(1, $calls);
        } finally {
            $request->getBody()->close();
        }
    }

    private static function nonSeekableRequest(string $bytes): ServerRequestInterface
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        fwrite($pair[0], $bytes);
        fclose($pair[0]);
        $factory = new Psr17Factory();
        $body = $factory->createStreamFromResource($pair[1]);
        self::assertFalse($body->isSeekable());
        return $factory->createServerRequest('POST', 'http://localhost/')->withBody($body);
    }
}
