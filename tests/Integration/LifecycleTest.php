<?php

declare(strict_types=1);

namespace PhpHono\Tests\Integration;

use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PhpHono\Context;
use PhpHono\Hono;
use PhpHono\Middleware\Psr15;
use PhpHono\Tests\Fixtures\Greeting;
use PhpHono\Tests\Fixtures\Identity;
use PhpHono\Tests\Fixtures\User;
use PhpHono\Tests\Unit\BodyStoreTest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use WeakReference;

final class LifecycleTest extends TestCase
{
    /**
     * The shared-container, bridge and cleanup sequence is one integration scenario.
     * @mago-expect lint:halstead
     */
    public function testBootstrapDiMapperAndRepeatedRequestsReleaseState(): void
    {
        $handler = new Greeting('hello ');
        $container = new class($handler) implements ContainerInterface {
            public int $calls = 0;

            public function __construct(
                private readonly Greeting $handler,
            ) {}

            public function get(string $id): Greeting
            {
                ++$this->calls;
                if (!$this->has($id)) {
                    throw new LogicException('Unknown service');
                }
                return $this->handler;
            }

            public function has(string $id): bool
            {
                return $id === Greeting::class;
            }
        };
        $app = new Hono();
        $references = [];
        $cleanup = 0;
        $app->use('*', static function (Context $c, callable $next) use (&$references, &$cleanup): void {
            $references[] = WeakReference::create($c);
            try {
                $next();
            } finally {
                self::assertNull($c->req->raw()->getAttribute(Identity::class));
                ++$cleanup;
            }
        });
        $app->use('*', Psr15::wrap(new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $r, RequestHandlerInterface $h): ResponseInterface
            {
                $name = $r->getHeaderLine('X-User');
                return $h->handle(
                    $name === ''
                        ? $r->withoutAttribute(Identity::class)
                        : $r->withAttribute(Identity::class, new User($name)),
                );
            }
        }));
        $app->use('*', static function (Context $c, callable $next) use (&$references): ?ResponseInterface {
            $c->set(Identity::class, null);
            $identity = $c->req->raw()->getAttribute(Identity::class);
            if (!$identity instanceof Identity) {
                return $c->text('Unauthorized', 401);
            }
            $references[] = WeakReference::create($identity);
            $c->set(Identity::class, $identity);
            $next();
            return null;
        });
        $app->get('/', $container->get(Greeting::class));
        $app->get('/error', static fn(Context $c): object => $c->extension(Greeting::class));
        for ($index = 0; $index < 300; ++$index) {
            $name = ($index % 3) === 0 ? '' : 'user-' . $index;
            $error = ($index % 5) === 0;
            $response = $app->request($error ? '/error' : '/', ['headers' => ['X-User' => $name]]);
            $expected = $error ? 500 : 200;
            self::assertSame($name === '' ? 401 : $expected, $response->getStatusCode());
            if ($response->getStatusCode() === 200) {
                self::assertSame('hello ' . $name, (string) $response->getBody());
            }
        }
        gc_collect_cycles();
        self::assertSame(1, $container->calls);
        self::assertSame(300, $cleanup);
        foreach ($references as $reference) {
            self::assertNull($reference->get());
        }
    }

    public function testNonSeekableBodyThroughMixedReaderChain(): void
    {
        $app = new Hono();
        $app->use('*', static function (Context $c, callable $next): void {
            self::assertSame('{"value":42}', $c->req->text());
            $next();
        });
        $app->use('*', Psr15::wrap(new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $r, RequestHandlerInterface $h): ResponseInterface
            {
                TestCase::assertSame('{"value":42}', $r->getBody()->getContents());
                return $h->handle($r->withParsedBody(['parsed' => true]));
            }
        }));
        $app->post('/', static function (Context $c): ResponseInterface {
            self::assertSame(['parsed' => true], $c->req->raw()->getParsedBody());
            return $c->json($c->req->json());
        });
        $request = new Psr17Factory()
            ->createServerRequest('POST', 'http://localhost/')
            ->withBody(BodyStoreTest::nonSeekable('{"value":42}'));
        self::assertSame('{"value":42}', (string) $app->handle($request)->getBody());
    }
}
