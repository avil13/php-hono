<?php

declare(strict_types=1);

namespace PhpHono\Tests\Unit;

use Nyholm\Psr7\Stream;
use PhpHono\Context;
use PhpHono\Hono;
use PhpHono\Http\StreamingBody;
use PhpHono\HttpException;
use PhpHono\Middleware\BasicAuth;
use PhpHono\Middleware\BearerAuth;
use PhpHono\Middleware\Compress;
use PhpHono\Middleware\Csrf;
use PhpHono\Middleware\ETag;
use PhpHono\Middleware\MethodNotAllowed;
use PhpHono\Middleware\TrailingSlash;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Related middleware cases share setup while keeping their HTTP assertions independent.
 * @mago-expect lint:too-many-methods
 */
final class AdvancedMiddlewareTest extends TestCase
{
    public function testBasicAuthParsesCredentialsAndShortCircuitsFailures(): void
    {
        $app = new Hono();
        $calls = 0;
        $app->use('*', BasicAuth::credentials('admin', 'pass:word', 'Members'));
        $app->get('/', static function (Context $context) use (&$calls): ResponseInterface {
            $calls++;
            return $context->text('secret');
        });
        foreach ([
            '',
            'Basic ???',
            'Basic ' . base64_encode('no-colon'),
            'Basic ' . base64_encode('admin:wrong'),
        ] as $authorization) {
            $response = $app->request('/', ['headers' => ['Authorization' => $authorization]]);
            self::assertSame(401, $response->getStatusCode());
            self::assertSame('Basic realm="Members", charset="UTF-8"', $response->getHeaderLine('WWW-Authenticate'));
            self::assertSame('Unauthorized', (string) $response->getBody());
        }
        self::assertSame(0, $calls);
        $response = $app->request('/', ['headers' => ['Authorization' => 'basic ' . base64_encode('admin:pass:word')]]);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $calls);
    }

    public function testBearerAuthDoesNotRunEndpointForMalformedOrInvalidTokens(): void
    {
        $app = new Hono();
        $app->use('*', BearerAuth::token('a-token_~+/=='));
        $app->get('/', static fn(Context $context): ResponseInterface => $context->text('accepted'));
        foreach (['', 'Bearer bad token', 'Bearer wrong', 'Basic a-token_~+/=='] as $authorization) {
            self::assertSame(
                401,
                $app->request('/', ['headers' => ['Authorization' => $authorization]])->getStatusCode(),
            );
        }
        self::assertSame(
            200,
            $app->request('/', ['headers' => ['Authorization' => 'Bearer a-token_~+/==']])->getStatusCode(),
        );
    }

    public function testVerifierFailureUsesTheApplicationErrorBoundaryWithoutExposingCredentials(): void
    {
        $app = new Hono();
        $app->use('*', new BearerAuth(static function (): bool {
            throw new RuntimeException('backend failure');
        }));
        $app->get('/', static fn(Context $context): ResponseInterface => $context->text('never'));
        $response = $app->request('/', ['headers' => ['Authorization' => 'Bearer private-token']]);
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('Internal Server Error', (string) $response->getBody());
    }

    /** @return iterable<string, array{string, array<string, string>, int}> */
    public static function csrfRequests(): iterable
    {
        yield 'safe GET' => ['GET', [], 200];
        yield 'lowercase safe method follows dispatcher' => ['get', [], 200];
        yield 'missing form headers' => ['POST', [], 403];
        yield 'same origin default port' => ['POST', ['Origin' => 'http://localhost:80'], 200];
        yield 'wrong scheme' => ['POST', ['Origin' => 'https://localhost'], 403];
        yield 'wrong port' => ['POST', ['Origin' => 'http://localhost:8080'], 403];
        yield 'null origin' => ['POST', ['Origin' => 'null'], 403];
        yield 'same-origin metadata' => ['POST', ['Sec-Fetch-Site' => 'same-origin'], 200];
        yield 'same-site metadata is not default' => ['POST', ['Sec-Fetch-Site' => 'same-site'], 403];
        yield 'Hono accepts either successful check' => [
            'POST',
            ['Sec-Fetch-Site' => 'same-origin', 'Origin' => 'https://elsewhere.test'],
            200,
        ];
        yield 'JSON is not a browser form type' => ['POST', ['Content-Type' => 'application/json'], 200];
        yield 'multipart cross origin' => [
            'POST',
            ['Content-Type' => 'multipart/form-data; boundary=x', 'Origin' => 'https://elsewhere.test'],
            403,
        ];
        yield 'proxy header cannot change request origin' => [
            'POST',
            [
                'Origin' => 'https://elsewhere.test',
                'X-Forwarded-Host' => 'elsewhere.test',
                'X-Forwarded-Proto' => 'https',
            ],
            403,
        ];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('csrfRequests')]
    public function testCsrfOriginAndFetchMetadataPolicy(string $method, array $headers, int $status): void
    {
        $app = new Hono();
        $app->use('*', new Csrf());
        $app->all('/', static fn(Context $context): ResponseInterface => $context->text('accepted'));
        self::assertSame($status, $app->request('/', ['method' => $method, 'headers' => $headers])->getStatusCode());
    }

    public function testCsrfExplicitOriginAllowList(): void
    {
        $app = new Hono();
        $app->use('*', new Csrf(['https://trusted.test'], []));
        $app->post('/', static fn(Context $context): ResponseInterface => $context->text('accepted'));
        self::assertSame(
            200,
            $app->request('/', [
                'method' => 'POST',
                'headers' => ['Origin' => 'https://trusted.test:443'],
            ])->getStatusCode(),
        );
        self::assertSame(
            403,
            $app->request('/', ['method' => 'POST', 'headers' => ['Origin' => 'http://localhost']])->getStatusCode(),
        );
    }

    public function testEtagEmptyBodyMultipleValidatorsAnd304Headers(): void
    {
        $app = new Hono();
        $app->use('*', new ETag());
        $app->get('/', static fn(Context $context): ResponseInterface => $context->text('', 200, [
            'Cache-Control' => 'private',
            'X-Retained' => 'yes',
        ]));
        $first = $app->request('/');
        $tag = '"' . hash('sha256', data: '') . '"';
        self::assertSame($tag, $first->getHeaderLine('ETag'));
        $cached = $app->request('/', ['headers' => ['If-None-Match' => '"other", W/' . $tag]]);
        self::assertSame(304, $cached->getStatusCode());
        self::assertSame('', (string) $cached->getBody());
        self::assertSame('private', $cached->getHeaderLine('Cache-Control'));
        self::assertSame('yes', $cached->getHeaderLine('X-Retained'));
        self::assertFalse($cached->hasHeader('Content-Length'));
        self::assertFalse($cached->hasHeader('Content-Type'));
    }

    public function testEtagHandlesQuotedCommasAndHeadWithoutReadingTheBody(): void
    {
        $app = new Hono();
        $app->use('*', new ETag());
        $app->get('/', static fn(Context $context): ResponseInterface => $context->text('body', 200, [
            'ETag' => '"one,two"',
        ]));
        $response = $app->request('/', ['method' => 'HEAD', 'headers' => ['If-None-Match' => '"other", W/"one,two"']]);
        self::assertSame(304, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
    }

    public function testEtagPreservesCursorAndSkipsNonSuccessResponses(): void
    {
        $body = Stream::create('abcdef');
        $body->seek(2);
        $app = new Hono();
        $app->use('*', new ETag(weak: true));
        $app->get('/', static fn(Context $context): ResponseInterface => $context->body($body));
        $app->get('/missing', static fn(Context $context): ResponseInterface => $context->text('missing', 404));
        self::assertSame('W/"' . hash('sha256', data: 'abcdef') . '"', $app->request('/')->getHeaderLine('ETag'));
        self::assertSame(2, $body->tell());
        self::assertFalse($app->request('/missing')->hasHeader('ETag'));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function encodings(): iterable
    {
        yield 'gzip' => ['gzip', true];
        yield 'gzip zero overrides wildcard' => ['gzip;q=0, *;q=1', false];
        yield 'wildcard' => ['*;q=0.5', true];
        yield 'unsupported only' => ['br', false];
        yield 'invalid quality' => ['gzip;q=9', false];
        yield 'identity preferred' => ['gzip;q=0.3, identity;q=1', false];
        yield 'gzip preferred' => ['gzip;q=0.9, identity;q=0.2', true];
    }

    #[DataProvider('encodings')]
    public function testCompressionNegotiationAndRepresentationTag(string $acceptEncoding, bool $compressed): void
    {
        $body = str_repeat('compressible ', times: 200);
        $app = new Hono();
        $app->use('*', new ETag());
        $app->use('*', new Compress(minimumLength: 0));
        $app->get('/', static fn(Context $context): ResponseInterface => $context->text($body, 200, [
            'Vary' => 'Origin',
        ]));
        $response = $app->request('/', ['headers' => ['Accept-Encoding' => $acceptEncoding]]);
        $bytes = (string) $response->getBody();
        self::assertSame($compressed ? 'gzip' : '', $response->getHeaderLine('Content-Encoding'));
        self::assertSame($body, $compressed ? gzdecode($bytes) : $bytes);
        self::assertSame('"' . hash('sha256', $bytes) . '"', $response->getHeaderLine('ETag'));
        self::assertSame('Origin, Accept-Encoding', $response->getHeaderLine('Vary'));
        if ($compressed) {
            self::assertSame((string) strlen($bytes), $response->getHeaderLine('Content-Length'));
        }
    }

    public function testCompressionWeakensAnExistingRepresentationTagAndPreservesVaryOn304(): void
    {
        $app = new Hono();
        $app->use('*', new Compress(minimumLength: 0));
        $app->use('*', new ETag());
        $app->get('/', static fn(Context $context): ResponseInterface => $context->text('abc'));
        $first = $app->request('/', ['headers' => ['Accept-Encoding' => 'gzip']]);
        self::assertSame('W/"' . hash('sha256', data: 'abc') . '"', $first->getHeaderLine('ETag'));
        $cached = $app->request('/', ['headers' => [
            'Accept-Encoding' => 'gzip',
            'If-None-Match' => $first->getHeaderLine('ETag'),
        ]]);
        self::assertSame(304, $cached->getStatusCode());
        self::assertSame('Accept-Encoding', $cached->getHeaderLine('Vary'));
        self::assertFalse($cached->hasHeader('Content-Encoding'));
    }

    public function testBodyTransformsDoNotReadNonSeekableBodies(): void
    {
        $body = $this->createMock(StreamInterface::class);
        $body->method('isReadable')->willReturn(true);
        $body->method('isSeekable')->willReturn(false);
        $body->expects(self::never())->method('read');
        $app = new Hono();
        $app->use('*', new ETag(), new Compress(minimumLength: 0));
        $app->get('/', static fn(Context $context): ResponseInterface => $context->body($body));
        $response = $app->request('/', ['headers' => ['Accept-Encoding' => 'gzip']]);
        self::assertFalse($response->hasHeader('ETag'));
        self::assertFalse($response->hasHeader('Content-Encoding'));
        self::assertSame($body, $response->getBody());
    }

    public function testBodyTransformsNeverStartStreamingProducers(): void
    {
        $started = 0;
        $app = new Hono();
        $app->use('*', new ETag(), new Compress(minimumLength: 0));
        $app->get('/', static function (Context $context) use (&$started): ResponseInterface {
            return $context->body(new StreamingBody(static function () use (&$started): iterable {
                $started++;
                yield 'chunk';
            }));
        });
        foreach (['HEAD', 'head', 'GET'] as $method) {
            $response = $app->request('/', ['method' => $method, 'headers' => ['Accept-Encoding' => 'gzip']]);
            self::assertSame(0, $started);
            self::assertFalse($response->hasHeader('ETag'));
            self::assertFalse($response->hasHeader('Content-Encoding'));
            if ($method === 'GET') {
                self::assertSame('chunk', $response->getBody()->getContents());
            }
        }
        self::assertSame(1, $started);
        $refused = $app->request('/', ['headers' => ['Accept-Encoding' => 'gzip, identity;q=0']]);
        self::assertSame(406, $refused->getStatusCode());
        self::assertSame(1, $started);
    }

    public function testCompressionNeverSendsAnExplicitlyForbiddenIdentityRepresentation(): void
    {
        $app = new Hono();
        $app->use('*', new Compress());
        $app->get('/', static fn(Context $context): ResponseInterface => $context->text('small'));
        foreach (['gzip;q=0, identity;q=0', '*;q=0', 'br, identity;q=0'] as $header) {
            $response = $app->request('/', ['headers' => ['Accept-Encoding' => $header]]);
            self::assertSame(406, $response->getStatusCode());
            self::assertSame('', (string) $response->getBody());
            self::assertSame('Accept-Encoding', $response->getHeaderLine('Vary'));
        }
        $response = $app->request('/', ['headers' => ['Accept-Encoding' => 'gzip, identity;q=0']]);
        self::assertSame('gzip', $response->getHeaderLine('Content-Encoding'));
        self::assertSame('small', gzdecode((string) $response->getBody()));
    }

    public function testCompressionFailureClosesItsTemporaryFileAndRestoresTheSourceCursor(): void
    {
        $opened = null;
        $streams = $this->createMock(StreamFactoryInterface::class);
        $streams
            ->expects(self::once())
            ->method('createStreamFromResource')
            ->willReturnCallback(static function ($resource) use (&$opened): StreamInterface {
                $opened = $resource;
                throw new RuntimeException('Stream factory failed');
            });
        $body = Stream::create('body');
        $body->seek(2);
        $app = new Hono();
        $app->use('*', new Compress(minimumLength: 0, streams: $streams));
        $app->get('/', static fn(Context $context): ResponseInterface => $context->body($body));
        $response = $app->request('/', ['headers' => ['Accept-Encoding' => 'gzip']]);
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('resource (closed)', gettype($opened));
        self::assertSame(2, $body->tell());
    }

    public function testCompressionPreservesPartialAndNoTransformRepresentations(): void
    {
        $app = new Hono();
        $app->use('*', new Compress(minimumLength: 0));
        $app->get('/range', static fn(Context $context): ResponseInterface => $context->text('abc', 206, [
            'Content-Range' => 'bytes 0-2/9',
        ]));
        $app->get('/fixed', static fn(Context $context): ResponseInterface => $context->text('abc', 200, [
            'Cache-Control' => 'public, no-transform',
        ]));
        foreach (['/range', '/fixed'] as $path) {
            $response = $app->request($path, ['headers' => ['Accept-Encoding' => 'gzip']]);
            self::assertFalse($response->hasHeader('Content-Encoding'));
            self::assertSame('abc', (string) $response->getBody());
        }
    }

    public function testHeadCompressionMetadataNeverReadsABody(): void
    {
        $body = $this->createMock(StreamInterface::class);
        $body->method('isReadable')->willReturn(true);
        $body->method('isSeekable')->willReturn(true);
        $body->expects(self::never())->method('read');
        $app = new Hono();
        $app->use('*', new Compress());
        $app->get('/', static fn(Context $context): ResponseInterface => $context->body($body, 200, [
            'ETag' => '"original"',
            'Content-Length' => '123',
        ]));
        $response = $app->request('/', ['method' => 'HEAD', 'headers' => ['Accept-Encoding' => 'gzip, identity;q=0']]);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('gzip', $response->getHeaderLine('Content-Encoding'));
        self::assertSame('W/"original"', $response->getHeaderLine('ETag'));
        self::assertFalse($response->hasHeader('Content-Length'));
    }

    public function testOptional405DoesNotReplaceIntentional404OrErrors(): void
    {
        $app = new Hono();
        $app->use('*', new MethodNotAllowed($app));
        $app->get('/item', static fn(Context $context): ResponseInterface => $context->text('not here', 404));
        $app->post('/item', static fn(Context $context): ResponseInterface => $context->text('created', 201));
        $app->all('/any', static fn(Context $context): ResponseInterface => $context->text('no', 404));
        $app->on('HEAD', '/head-only', static fn(Context $context): ResponseInterface => $context->text('unused'));
        $app->use('/error', static function (): never {
            throw new HttpException(404, 'hidden');
        });
        $app->get('/error', static fn(Context $context): ResponseInterface => $context->text('unused'));
        self::assertSame(404, $app->request('/item')->getStatusCode());
        self::assertSame(404, $app->request('/item', ['method' => 'get'])->getStatusCode());
        $response = $app->request('/item', ['method' => 'PUT']);
        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, HEAD, POST', $response->getHeaderLine('Allow'));
        self::assertSame(404, $app->request('/any', ['method' => 'PUT'])->getStatusCode());
        self::assertSame(404, $app->request('/head-only', ['method' => 'PUT'])->getStatusCode());
        self::assertSame(404, $app->request('/error', ['method' => 'PUT'])->getStatusCode());
    }

    public function test405UsesTheApplicationsConfiguredPathLimit(): void
    {
        $app = new Hono(maxPathLength: 10_000);
        $app->use('*', new MethodNotAllowed($app));
        $path = '/' . str_repeat('a', times: 9000);
        $app->get($path, static fn(Context $context): ResponseInterface => $context->text('ok'));
        $response = $app->request($path, ['method' => 'POST']);
        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, HEAD', $response->getHeaderLine('Allow'));
    }

    public function testTrailingSlashRedirectPreservesQueryAndNeverRedirectsUnsafeMethods(): void
    {
        $app = new Hono();
        $app->use('*', new TrailingSlash());
        $app->get('/about', static fn(Context $context): ResponseInterface => $context->text('about'));
        $response = $app->request('/about/?x=1');
        self::assertSame(301, $response->getStatusCode());
        self::assertSame('http://localhost/about?x=1', $response->getHeaderLine('Location'));
        self::assertSame(200, $app->request('/about')->getStatusCode());
        self::assertSame(404, $app->request('/about/', ['method' => 'POST'])->getStatusCode());
        self::assertSame(404, $app->request('/')->getStatusCode());
    }

    public function testAppendSlashAlwaysRedirectsBeforeEndpoint(): void
    {
        $app = new Hono();
        $app->use('*', new TrailingSlash(append: true, alwaysRedirect: true));
        $app->get('/about', static fn(Context $context): ResponseInterface => $context->text('not reached'));
        self::assertSame(301, $app->request('/about')->getStatusCode());
        self::assertSame(404, $app->request('/about/')->getStatusCode());
    }
}
