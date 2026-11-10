<?php

declare(strict_types=1);

namespace PhpHono\Tests\Unit;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PhpHono\Context;
use PhpHono\Hono;
use PhpHono\Http\ResponseFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

final class ResponseFactoryTest extends TestCase
{
    public function testAlternativeGuzzlePsr7Implementation(): void
    {
        $factory = new HttpFactory();
        $app = new Hono(responseFactory: $factory, streamFactory: $factory);
        $app->post('/echo', static fn(Context $c): ResponseInterface => $c->json($c->req->json(), 201));
        $request = $factory
            ->createServerRequest('POST', 'https://example.test/echo')
            ->withBody($factory->createStream('{"from":"Guzzle"}'));
        $response = $app->handle($request);
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('{"from":"Guzzle"}', (string) $response->getBody());
    }

    public function testInjectedFactoryAndJsonFailure(): void
    {
        $factory = new class implements ResponseFactoryInterface {
            public int $calls = 0;

            public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
            {
                ++$this->calls;
                return new Psr17Factory()
                    ->createResponse($code, $reasonPhrase)
                    ->withHeader('X-Factory', 'custom');
            }
        };
        $app = new Hono(responseFactory: $factory, streamFactory: new Psr17Factory());
        $app->get('/', static fn(Context $c): ResponseInterface => $c->json(['text' => 'Привет / 世界']));
        $app->get('/error', static fn(Context $c): ResponseInterface => $c->json(NAN));
        $response = $app->request('https://never-contacted.invalid/');
        self::assertSame('{"text":"Привет / 世界"}', (string) $response->getBody());
        self::assertSame('custom', $response->getHeaderLine('X-Factory'));
        self::assertSame(500, $app->request('/error')->getStatusCode());
        self::assertSame(2, $factory->calls);
    }

    public function testHeaderInjectionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ResponseFactory()->create(headers: ['Location' => "/safe\r\nX-Injected: yes"]);
    }

    public function testUnsupportedRequestOptionsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Hono()->request('/', ['unsupported' => true]);
    }
}
