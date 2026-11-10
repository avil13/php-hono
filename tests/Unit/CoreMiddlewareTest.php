<?php

declare(strict_types=1);

namespace PhpHono\Tests\Unit;

use InvalidArgumentException;
use PhpHono\Context;
use PhpHono\Hono;
use PhpHono\Middleware\BodyLimit;
use PhpHono\Middleware\Cors;
use PhpHono\Middleware\Logger;
use PhpHono\Middleware\RequestId;
use PhpHono\Middleware\SecureHeaders;
use PhpHono\Middleware\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Related middleware contracts are kept together for integration coverage.
 *
 * @mago-expect lint:too-many-methods
 */
final class CoreMiddlewareTest extends TestCase
{
    public function testValidatorNormalizesQueryAndStoresTransformedData(): void
    {
        $seen = null;
        $app = new Hono();
        $app->get(
            '/',
            new Validator('query', static function (mixed $input) use (&$seen): mixed {
                $seen = $input;
                return ['normalized' => true];
            }),
            static fn(Context $c): ResponseInterface => $c->json($c->req->valid('query')),
        );
        $response = $app->request('/?tag=a&tag=b&single=x&literal.key=y');
        self::assertSame(['tag' => ['a', 'b'], 'single' => 'x', 'literal.key' => 'y'], $seen);
        self::assertSame('{"normalized":true}', (string) $response->getBody());
    }

    public function testValidatorCanShortCircuitAndPreservesParsingErrors(): void
    {
        $called = false;
        $app = new Hono();
        $app->post(
            '/',
            new Validator('json', static fn(mixed $input, Context $c): ResponseInterface => $c->text('invalid', 422)),
            static function (Context $c) use (&$called): ResponseInterface {
                $called = true;
                return $c->text('unreachable');
            },
        );
        self::assertSame(
            422,
            $app->request('/', [
                'method' => 'POST',
                'body' => '{}',
                'headers' => ['Content-Type' => 'application/json'],
            ])->getStatusCode(),
        );
        self::assertFalse($called);
        self::assertSame(
            400,
            $app->request('/', [
                'method' => 'POST',
                'body' => '{',
                'headers' => ['Content-Type' => 'application/json'],
            ])->getStatusCode(),
        );
    }

    public function testValidatorUsesMediaTypeAndSupportsFormCookieParamAndHeader(): void
    {
        $app = new Hono();
        $identity = static fn(mixed $input): mixed => $input;
        $app->post(
            '/:id',
            new Validator('json', $identity),
            new Validator('form', $identity),
            new Validator('cookie', $identity),
            new Validator('param', $identity),
            new Validator('header', $identity),
            static function (Context $c): ResponseInterface {
                return $c->json([
                    'json' => $c->req->valid('json'),
                    'form' => $c->req->valid('form'),
                    'cookie' => $c->req->valid('cookie'),
                    'param' => $c->req->valid('param'),
                    'header' => $c->req->valid('header'),
                ]);
            },
        );
        $response = $app->request('/42', [
            'method' => 'POST',
            'body' => 'a=1&a=2',
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded', 'Cookie' => 'name=Alice%20B'],
        ]);
        self::assertStringContainsString('"json":[]', (string) $response->getBody());
        self::assertStringContainsString('"form":{"a":["1","2"]}', (string) $response->getBody());
        self::assertStringContainsString('"cookie":{"name":"Alice B"}', (string) $response->getBody());
        self::assertStringContainsString('"param":{"id":"42"}', (string) $response->getBody());
        self::assertStringContainsString(
            '"content-type":"application/x-www-form-urlencoded"',
            (string) $response->getBody(),
        );
    }

    public function testBodyLimitMeasuresActualBodyAndCannotRaiseApplicationLimit(): void
    {
        $app = new Hono(maxBodySize: 3);
        $app->use('*', new BodyLimit(100));
        $app->post('/', static fn(Context $c): ResponseInterface => $c->text('ignored body'));
        foreach ([[], ['Content-Length' => '1'], ['Content-Length' => '999999999999999999999999999']] as $headers) {
            self::assertSame(
                413,
                $app->request('/', ['method' => 'POST', 'body' => 'four', 'headers' => $headers])->getStatusCode(),
            );
        }
        self::assertSame(200, $app->request('/', ['method' => 'POST', 'body' => '123'])->getStatusCode());
        self::assertSame(
            400,
            $app->request('/', ['method' => 'POST', 'headers' => ['Content-Length' => '3,3']])->getStatusCode(),
        );
    }

    public function testBodyLimitCustomErrorAndZeroLimit(): void
    {
        $app = new Hono();
        $app->use('*', new BodyLimit(0, static fn(Context $c): ResponseInterface => $c->json([
            'tooLarge' => true,
        ], 413)));
        $app->post('/', static fn(Context $c): ResponseInterface => $c->text($c->req->text()));
        self::assertSame(200, $app->request('/', ['method' => 'POST'])->getStatusCode());
        $response = $app->request('/', ['method' => 'POST', 'body' => '1']);
        self::assertSame(413, $response->getStatusCode());
        self::assertSame('{"tooLarge":true}', (string) $response->getBody());
    }

    public function testCorsPreflightUsesConfiguredPolicyAndDoesNotRunRoute(): void
    {
        $app = new Hono();
        $app->use(
            '*',
            new Cors(origin: ['https://client.test'], credentials: true, exposeHeaders: ['X-Result'], maxAge: 600),
        );
        $app->options('/', static fn(Context $c): ResponseInterface => $c->text('ordinary options'));
        $response = $app->request('/', [
            'method' => 'OPTIONS',
            'headers' => [
                'Origin' => 'https://client.test',
                'Access-Control-Request-Method' => 'QUERY',
                'Access-Control-Request-Headers' => 'X-Token, Content-Type',
            ],
        ]);
        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
        self::assertFalse($response->hasHeader('Content-Type'));
        self::assertFalse($response->hasHeader('Content-Length'));
        self::assertSame('https://client.test', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
        self::assertStringContainsString('QUERY', $response->getHeaderLine('Access-Control-Allow-Methods'));
        self::assertSame('X-Token,Content-Type', $response->getHeaderLine('Access-Control-Allow-Headers'));
        self::assertSame('Origin, Access-Control-Request-Headers', $response->getHeaderLine('Vary'));
        self::assertSame('600', $response->getHeaderLine('Access-Control-Max-Age'));
        self::assertSame(
            204,
            $app->request('/', [
                'method' => 'options',
                'headers' => ['Origin' => 'https://client.test', 'Access-Control-Request-Method' => 'GET'],
            ])->getStatusCode(),
        );
        self::assertSame('ordinary options', (string) $app->request('/', ['method' => 'OPTIONS'])->getBody());
    }

    public function testCorsAppliesOnErrorsAndReplacesConflictingEndpointPolicy(): void
    {
        $app = new Hono();
        $app->use('*', new Cors(origin: 'https://client.test'));
        $app->get('/', static fn(Context $c): ResponseInterface => $c->text('ok', 200, [
            'Vary' => 'Accept-Encoding, origin',
            'Set-Cookie' => ['a=1', 'b=2'],
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Credentials' => 'true',
        ]));
        $app->get('/error', static function (): never {
            throw new RuntimeException('private');
        });
        $allowed = $app->request('/', ['headers' => ['Origin' => 'https://client.test']]);
        self::assertSame('Accept-Encoding, origin', $allowed->getHeaderLine('Vary'));
        self::assertSame(['a=1', 'b=2'], $allowed->getHeader('Set-Cookie'));
        self::assertFalse($allowed->hasHeader('Access-Control-Allow-Credentials'));
        self::assertFalse($app->request('/', ['headers' => ['Origin' => 'https://other.test']])->hasHeader(
            'Access-Control-Allow-Origin',
        ));
        self::assertFalse($app->request('/')->hasHeader('Access-Control-Allow-Origin'));
        $error = $app->request('/error', ['headers' => ['Origin' => 'https://client.test']]);
        self::assertSame(500, $error->getStatusCode());
        self::assertSame('https://client.test', $error->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testCorsRejectsWildcardCredentials(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Cors(credentials: true);
    }

    public function testCorsDynamicOriginAndInvalidRequestedHeader(): void
    {
        $app = new Hono();
        $app->use('*', new Cors(origin: static fn(string $origin, Context $c): ?string => $c->req->path() === '/'
            ? $origin
            : null));
        $app->get('/', static fn(Context $c): ResponseInterface => $c->text('ok'));
        self::assertSame(
            'https://client.test',
            $app->request('/', ['headers' => ['Origin' => 'https://client.test']])->getHeaderLine(
                'Access-Control-Allow-Origin',
            ),
        );
        self::assertSame(
            400,
            $app->request('/', [
                'method' => 'OPTIONS',
                'headers' => [
                    'Origin' => 'https://client.test',
                    'Access-Control-Request-Method' => 'GET',
                    'Access-Control-Request-Headers' => 'Invalid Name',
                ],
            ])->getStatusCode(),
        );
    }

    public function testSecureHeadersDefaultsOverridesAndErrorResponses(): void
    {
        $app = new Hono();
        $app->use('*', new SecureHeaders([
            'Content-Security-Policy' => "default-src 'self'",
            'X-Frame-Options' => null,
        ]));
        $app->get('/', static fn(Context $c): ResponseInterface => $c->text('ok', 200, [
            'X-Powered-By' => 'PHP',
            'Set-Cookie' => ['a=1', 'b=2'],
        ]));
        $response = $app->request('/');
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame("default-src 'self'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertFalse($response->hasHeader('X-Frame-Options'));
        self::assertFalse($response->hasHeader('X-Powered-By'));
        self::assertSame(['a=1', 'b=2'], $response->getHeader('Set-Cookie'));
        self::assertSame('no-referrer', $app->request('/missing')->getHeaderLine('Referrer-Policy'));
    }

    public function testRequestIdValidatesAndIsolatesRequestValues(): void
    {
        $app = new Hono();
        $app->use('*', new RequestId());
        $app->get('/', static fn(Context $c): ResponseInterface => $c->json($c->get('requestId')));
        $valid = $app->request('/', ['headers' => ['X-Request-Id' => 'abc_123-xyz=']]);
        self::assertSame('abc_123-xyz=', $valid->getHeaderLine('X-Request-Id'));
        self::assertSame('"abc_123-xyz="', (string) $valid->getBody());
        $ids = [];
        foreach (['unsafe value', str_repeat('a', times: 256), ''] as $incoming) {
            $response = $app->request('/', ['headers' => ['X-Request-Id' => $incoming]]);
            $id = $response->getHeaderLine('X-Request-Id');
            self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $id);
            self::assertSame('"' . $id . '"', (string) $response->getBody());
            $ids[] = $id;
        }
        self::assertCount(3, array_unique($ids));
    }

    public function testLoggerRecordsResponseWithoutQueryAndSurvivesBrokenSinks(): void
    {
        $entries = [];
        $app = new Hono();
        $app->use(
            '*',
            new RequestId(),
            new Logger(static function (array $entry) use (&$entries): void {
                $entries[] = $entry;
            }),
        );
        $app->get('/error', static function (): never {
            throw new RuntimeException('private');
        });
        $response = $app->request('/missing?token=secret', ['headers' => [
            'X-Request-Id' => 'trace-id',
            'Authorization' => 'Bearer private',
        ]]);
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('GET', $entries[0]['method']);
        self::assertSame('/missing', $entries[0]['path']);
        self::assertSame(404, $entries[0]['status']);
        self::assertSame('trace-id', $entries[0]['requestId']);
        self::assertGreaterThanOrEqual(0.0, $entries[0]['durationMs']);
        self::assertCount(5, $entries[0]);
        $app->request('/error');
        self::assertSame(500, $entries[1]['status']);

        $failures = 0;
        $broken = new Hono();
        $broken->use(
            '*',
            new Logger(static function (array $entry): never {
                throw new RuntimeException('sink failed');
            }, static function (Throwable $error) use (&$failures): never {
                ++$failures;
                throw new RuntimeException('diagnostic failed', previous: $error);
            }),
        );
        $broken->get('/', static fn(Context $c): ResponseInterface => $c->text('unaffected'));
        self::assertSame('unaffected', (string) $broken->request('/')->getBody());
        self::assertSame(1, $failures);
    }
}
