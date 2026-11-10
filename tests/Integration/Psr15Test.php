<?php

declare(strict_types=1);

namespace PhpHono\Tests\Integration;

use Closure;
use Middlewares\ResponseTime;
use Middlewares\Uuid;
use Nyholm\Psr7\Factory\Psr17Factory;
use PhpHono\Context;
use PhpHono\Hono;
use PhpHono\Middleware\Psr15;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use stdClass;
use Throwable;

final class Psr15Test extends TestCase
{
    public function testRealPackagesOnSuccessNotFoundErrorAndHead(): void
    {
        $app = new Hono();
        $app->use('*', Psr15::wrap(new ResponseTime()), Psr15::wrap(new Uuid()));
        $app->get('/', static fn(Context $c): ResponseInterface => $c->text($c->req->header('X-Uuid') ?? 'missing'));
        $app->get('/error', static function (): never {
            throw new RuntimeException('private');
        });
        $previous = null;
        foreach ([
            ['/',       'GET',  200],
            ['/absent', 'GET',  404],
            ['/error',  'GET',  500],
            ['/',       'HEAD', 200],
        ] as [$path, $method, $status]) {
            $response = $app->request($path, ['method' => $method]);
            $uuid = $response->getHeaderLine('X-Uuid');
            self::assertSame($status, $response->getStatusCode());
            self::assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
                $uuid,
            );
            self::assertNotSame($previous, $uuid);
            self::assertMatchesRegularExpression(
                '/^[0-9]+\.[0-9]{3}ms$/D',
                $response->getHeaderLine('X-Response-Time'),
            );
            if ($path === '/') {
                self::assertSame($method === 'HEAD' ? '' : $uuid, (string) $response->getBody());
            }
            $previous = $uuid;
        }
    }

    public function testAttributesBodyReplayAndExactHeaderRemoval(): void
    {
        $app = new Hono();
        $app->use('*', static function (Context $c, callable $next): void {
            self::assertSame('original', $c->req->text());
            $next();
            self::assertNull($c->req->raw()->getAttribute('identity'));
            self::assertSame('alice', $c->get('mapped-identity'));
        });
        $app->use(
            '*',
            Psr15::wrap(self::middleware(static function (
                ServerRequestInterface $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface {
                self::assertSame('original', $request->getBody()->getContents());
                $response = $handler->handle($request->withAttribute('identity', 'alice'));
                return $response->withoutHeader('Set-Cookie')->withoutHeader('X-Remove')->withHeader('X-Psr', 'yes');
            })),
        );
        $app->post('/', static function (Context $c): ResponseInterface {
            $c->set('mapped-identity', $c->req->raw()->getAttribute('identity'));
            self::assertSame('original', $c->req->text());
            return $c->text('ok', 200, ['Set-Cookie' => ['secret=1'], 'X-Remove' => 'yes']);
        });
        $response = $app->request('/', ['method' => 'POST', 'body' => 'original']);
        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Set-Cookie'));
        self::assertFalse($response->hasHeader('X-Remove'));
        self::assertSame('yes', $response->getHeaderLine('X-Psr'));
    }

    public function testReplacementBodyInvalidatesJsonAndEnforcesLimit(): void
    {
        $app = new Hono(maxBodySize: 32);
        $app->use('*', static function (Context $c, callable $next): void {
            self::assertInstanceOf(stdClass::class, $c->req->json());
            $next();
        });
        $app->use(
            '*',
            Psr15::wrap(self::middleware(static function (
                ServerRequestInterface $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface {
                $body = new Psr17Factory()->createStream('{"new":true}');
                return $handler->handle($request->withBody($body));
            })),
        );
        $app->post('/', static fn(Context $c): ResponseInterface => $c->json($c->req->json()));
        self::assertSame(
            '{"new":true}',
            (string) $app->request('/', ['method' => 'POST', 'body' => '{"old":true}'])->getBody(),
        );

        $limited = new Hono(maxBodySize: 3);
        $limited->use(
            '*',
            Psr15::wrap(self::middleware(static fn(
                ServerRequestInterface $r,
                RequestHandlerInterface $h,
            ): ResponseInterface => $h->handle($r->withBody(new Psr17Factory()->createStream('long'))))),
        );
        $limited->post('/', static fn(Context $c): ResponseInterface => $c->text('not reached'));
        self::assertSame(413, $limited->request('/', ['method' => 'POST'])->getStatusCode());
    }

    public function testShortCircuitAndDoubleHandleAndRewrite(): void
    {
        $app = new Hono();
        $app->use(
            '*',
            Psr15::wrap(self::middleware(static fn(): ResponseInterface => new Psr17Factory()->createResponse(401))),
        );
        $app->get('/', static function (): never {
            self::fail('Short circuit must not invoke endpoint');
        });
        self::assertSame(401, $app->request('/')->getStatusCode());

        foreach (['repeat', 'rewrite'] as $mode) {
            $invalid = new Hono();
            $invalid->use(
                '*',
                Psr15::wrap(self::middleware(static function (
                    ServerRequestInterface $r,
                    RequestHandlerInterface $h,
                ) use ($mode): ResponseInterface {
                    if ($mode === 'rewrite') {
                        return $h->handle($r->withMethod('POST'));
                    }
                    $h->handle($r);
                    return $h->handle($r);
                })),
            );
            $invalid->get('/', static fn(Context $c): ResponseInterface => $c->text('ok'));
            self::assertSame(500, $invalid->request('/')->getStatusCode());
        }
    }

    public function testPsrErrorMiddlewareReceivesHandledResponse(): void
    {
        $app = new Hono();
        $app->use(
            '*',
            Psr15::wrap(self::middleware(static function (
                ServerRequestInterface $r,
                RequestHandlerInterface $h,
            ): ResponseInterface {
                try {
                    return $h->handle($r)->withHeader('X-After', 'yes');
                } catch (Throwable) {
                    self::fail('Endpoint error must already be handled by Hono');
                }
            })),
        );
        $app->get('/', static function (): never {
            throw new RuntimeException('secret');
        });
        $response = $app->request('/');
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('yes', $response->getHeaderLine('X-After'));
    }

    public function testExternalPsrMiddlewareMayRewriteBeforeRouting(): void
    {
        $app = new Hono();
        $app->post('/target', static fn(Context $c): ResponseInterface => $c->text('rewritten'));
        $request = new Psr17Factory()->createServerRequest('GET', 'https://example.test/source');
        $middleware = self::middleware(static fn(
            ServerRequestInterface $r,
            RequestHandlerInterface $h,
        ): ResponseInterface => $h->handle($r->withMethod('POST')->withUri($r->getUri()->withPath('/target'))));
        self::assertSame('rewritten', (string) $middleware->process($request, $app)->getBody());
    }

    /** @param callable(ServerRequestInterface, RequestHandlerInterface): ResponseInterface $callback */
    private static function middleware(callable $callback): MiddlewareInterface
    {
        return new class(Closure::fromCallable($callback)) implements MiddlewareInterface {
            /** @param Closure(ServerRequestInterface, RequestHandlerInterface): ResponseInterface $callback */
            public function __construct(
                private readonly Closure $callback,
            ) {}

            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface {
                return ($this->callback)($request, $handler);
            }
        };
    }
}
