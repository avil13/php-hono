<?php

declare(strict_types=1);

namespace PhpHono\Tests\Unit;

use LogicException;
use PhpHono\Context;
use PhpHono\Hono;
use PhpHono\HttpException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

final class HonoTest extends TestCase
{
    public function testOverlappingRoutesAndOnionOrder(): void
    {
        $trace = [];
        $app = new Hono();
        $app->use('/users/*', static function (Context $c, callable $next) use (&$trace): void {
            $trace[] = 'before';
            $next();
            $trace[] = 'after';
            $c->header('X-Middleware', 'yes');
        });
        $app->get('/users/:id', static function (Context $c, callable $next) use (&$trace): void {
            $trace[] = $c->req->param('id');
            $next();
        });
        $app->get('/users/me', static fn(Context $c): ResponseInterface => $c->text('profile'));
        $response = $app->request('/users/me');
        self::assertSame(['before', 'me', 'after'], $trace);
        self::assertSame('profile', (string) $response->getBody());
        self::assertSame('yes', $response->getHeaderLine('X-Middleware'));
    }

    public function testExplicitReplacementAndIgnoredReturnAfterNext(): void
    {
        $app = new Hono();
        $app->use('*', static function (Context $c, callable $next): ResponseInterface {
            $next();
            return $c->text('ignored');
        });
        $app->get('/', static fn(Context $c): ResponseInterface => $c->text('original'));
        self::assertSame('original', (string) $app->request('/')->getBody());

        $other = new Hono();
        $other->use('*', static function (Context $c, callable $next): void {
            $next();
            $c->setResponse($c->text('replacement', 202));
        });
        $other->get('/', static fn(Context $c): ResponseInterface => $c->json(['old' => true], 200, [
            'ETag' => 'old',
            'X-Keep' => 'yes',
        ]));
        $response = $other->request('/');
        self::assertSame(202, $response->getStatusCode());
        self::assertSame('replacement', (string) $response->getBody());
        self::assertFalse($response->hasHeader('ETag'));
        self::assertSame('yes', $response->getHeaderLine('X-Keep'));
    }

    public function testDownstreamErrorsAreResponsesAndFailingErrorHandlerFallsBack(): void
    {
        $app = new Hono();
        $app->use('*', static function (Context $c, callable $next): void {
            $next();
            $c->header('X-After', 'yes');
        });
        $app->get('/', static function (): never {
            throw new RuntimeException('private detail');
        });
        $response = $app->request('/');
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('Internal Server Error', (string) $response->getBody());
        self::assertSame('yes', $response->getHeaderLine('X-After'));
        $app->onError(static function (): never {
            throw new RuntimeException('another private detail');
        });
        self::assertSame('Internal Server Error', (string) $app->request('/')->getBody());
    }

    public function testMissingResponseAndDoubleNextAreErrors(): void
    {
        foreach ([false, true] as $twice) {
            $app = new Hono();
            $app->use('*', static function (Context $c, callable $next) use ($twice): void {
                if ($twice) {
                    $next();
                    $next();
                }
            });
            $app->get('/', static fn(Context $c): ResponseInterface => $c->text('ok'));
            self::assertSame(500, $app->request('/')->getStatusCode());
        }
    }

    public function testHeadBodylessStatusesAndDefaultMethodMiss(): void
    {
        $app = new Hono();
        $app->get('/', static fn(Context $c): ResponseInterface => $c->text($c->req->method(), 200, [
            'Content-Length' => '4',
        ]));
        $app->get('/empty', static fn(Context $c): ResponseInterface => $c->text('discard', 204));
        $head = $app->request('/', ['method' => 'HEAD']);
        self::assertSame('', (string) $head->getBody());
        self::assertSame('4', $head->getHeaderLine('Content-Length'));
        $lowercaseHead = $app->request('/', ['method' => 'head']);
        self::assertSame(200, $lowercaseHead->getStatusCode());
        self::assertSame('', (string) $lowercaseHead->getBody());
        self::assertSame('', (string) $app->request('/empty')->getBody());
        self::assertSame(404, $app->request('/', ['method' => 'POST'])->getStatusCode());
    }

    public function testMountedErrorHandlerSnapshotAndBasePathFreeze(): void
    {
        $child = new Hono();
        $child->onError(static fn(Throwable $e, Context $c): ResponseInterface => $c->text('child', 422));
        $child->get('/x', static function (): never {
            throw new RuntimeException();
        });
        $app = new Hono();
        $app->route('/v1', $child);
        $child->get('/later', static fn(Context $c): ResponseInterface => $c->text('later'));
        $view = $app->basePath('/v2');
        $view->get('/x', static fn(Context $c): ResponseInterface => $c->text('view'));
        self::assertSame('child', (string) $app->request('/v1/x')->getBody());
        self::assertSame(404, $app->request('/v1/later')->getStatusCode());
        self::assertSame('view', (string) $app->request('/v2/x')->getBody());
        $this->expectException(LogicException::class);
        $view->get('/late', static fn(Context $c): ResponseInterface => $c->text('late'));
    }

    public function testContextStateIsolatedAcrossRequests(): void
    {
        $app = new Hono(env: ['mode' => 'configured']);
        $app->get('/', static function (Context $c): ResponseInterface {
            $old = $c->get('old');
            $c->set('old', true);
            return $c->json(['old' => $old, 'env' => $c->env]);
        });
        self::assertSame(
            '{"old":null,"env":{"mode":"one"}}',
            (string) $app->request('/', [], ['mode' => 'one'])->getBody(),
        );
        self::assertSame('{"old":null,"env":[]}', (string) $app->request('/')->getBody());
    }

    public function testHttpExceptionAndMalformedRequest(): void
    {
        $app = new Hono();
        $app->get('/error', static function (Context $c): never {
            throw new HttpException(409, 'Conflict', $c->text('custom', 400));
        });
        self::assertSame(409, $app->request('/error')->getStatusCode());
        self::assertSame('custom', (string) $app->request('/error')->getBody());
        self::assertSame(400, $app->request('/bad%GG')->getStatusCode());
    }
}
