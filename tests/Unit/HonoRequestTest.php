<?php

declare(strict_types=1);

namespace PhpHono\Tests\Unit;

use InvalidArgumentException;
use LogicException;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PhpHono\HonoRequest;
use PhpHono\HttpException;
use PhpHono\Internal\BodyStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Each public request read helper and forwarded-view contract has an independent case.
 *
 * @mago-expect lint:too-many-methods
 */
final class HonoRequestTest extends TestCase
{
    public function testQueriesPreserveRepeatedNamesEmptyValuesAndLiteralKeys(): void
    {
        $request = new HonoRequest(new ServerRequest(
            'GET',
            'http://localhost/search?tag=php&tag=http&q=&flag&user.name=Ann&user+name=Ada&tag%5B%5D=x&=empty-name',
        ));

        self::assertSame('php', $request->query('tag'));
        self::assertSame(['php', 'http'], $request->queries('tag'));
        self::assertSame('', $request->query('q'));
        self::assertSame('', $request->query('flag'));
        self::assertNull($request->query('missing'));
        self::assertSame('Ann', $request->query('user.name'));
        self::assertSame('Ada', $request->query('user name'));
        self::assertSame('x', $request->query('tag[]'));
        self::assertSame('empty-name', $request->query(''));
    }

    #[DataProvider('invalidTargets')]
    public function testMalformedTargetIsRejectedBeforeReadingQuery(string $target): void
    {
        $raw = new ServerRequest('GET', 'http://localhost/')->withRequestTarget($target);
        $this->expectException(HttpException::class);
        new HonoRequest($raw);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTargets(): iterable
    {
        yield 'broken path escape' => ['/broken%'];
        yield 'broken query escape' => ['/search?q=%G0'];
        yield 'invalid path utf8' => ['/%FF'];
        yield 'invalid query utf8' => ['/search?q=%C0%AF'];
    }

    public function testPathAndAlreadyDecodedParametersAreNotDecodedAgain(): void
    {
        $request = new HonoRequest(new ServerRequest('GET', 'http://localhost/files/a%2Fb'), ['id' => 'a/b']);
        self::assertSame('/files/a%2Fb', $request->path());
        self::assertSame('a/b', $request->param('id'));
        $request->setParams(['id' => '%2F']);
        self::assertSame('%2F', $request->param('id'));
        self::assertNull($request->param('other'));
    }

    public function testHeaderLookupIsCaseInsensitiveAndPreservesEmptyHeader(): void
    {
        $request = new HonoRequest(new ServerRequest('HEAD', '/', ['X-Test' => ['one', 'two'], 'X-Empty' => '']));
        self::assertSame('HEAD', $request->method());
        self::assertSame('one, two', $request->header('x-test'));
        self::assertSame('', $request->header('X-Empty'));
        self::assertNull($request->header('Missing'));
        self::assertSame(['x-test' => 'one, two', 'x-empty' => ''], $request->header());
    }

    public function testNonSeekableJsonAndBridgeReplayShareBodyBytes(): void
    {
        $body = '{"name":"Ada","big":' . PHP_INT_MAX . '0}';
        $raw = new ServerRequest('POST', '/')->withBody(BodyStoreTest::nonSeekable($body));
        $request = new HonoRequest($raw);
        $data = $request->json();
        self::assertInstanceOf(stdClass::class, $data);
        self::assertSame('Ada', $data->name);
        self::assertSame(PHP_INT_MAX . '0', $data->big);

        $prepared = $raw->withBody($request->bodyStore()->replay());
        self::assertSame('{"name"', $prepared->getBody()->read(7));
        $forwarded = $request->withRaw($prepared->withAttribute('identity', 42));
        self::assertSame($body, $forwarded->text());
        self::assertSame(42, $forwarded->raw()->getAttribute('identity'));
        self::assertSame($data, $forwarded->json());
    }

    public function testInvalidJsonProduces400(): void
    {
        $request = new HonoRequest(new ServerRequest('POST', '/', [], '{'));

        try {
            $request->json();
            self::fail('Malformed JSON was accepted');
        } catch (HttpException $exception) {
            self::assertSame(400, $exception->status);
        }
    }

    public function testUrlencodedFormPreservesNamesAndAppliesAllPolicy(): void
    {
        $request = new HonoRequest(
            new ServerRequest(
                'POST',
                '/',
                ['Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8'],
                'tag=php&tag=http&list%5B%5D=a&list%5B%5D=b&single=x&user.name=Ada+Lovelace',
            ),
        );

        self::assertSame(
            [
                'tag' => 'http',
                'list[]' => ['a', 'b'],
                'single' => 'x',
                'user.name' => 'Ada Lovelace',
            ],
            $request->parseBody(),
        );
        self::assertSame(['php', 'http'], $request->parseBody(true)['tag']);
        self::assertSame('x', $request->parseBody(true)['single']);
    }

    public function testMultipartNeedsExplicitParserMarkerEvenWhenEmpty(): void
    {
        $raw = new ServerRequest('POST', '/', ['Content-Type' => 'multipart/form-data; boundary=test']);
        $request = new HonoRequest($raw->withAttribute('php_hono.multipart_parsed', true));
        self::assertSame([], $request->parseBody());
        $this->expectException(HttpException::class);
        new HonoRequest($raw)->parseBody();
    }

    public function testMultipartAllOptionIsExplicitlyUnsupported(): void
    {
        $raw = new ServerRequest('POST', '/', ['Content-Type' => 'multipart/form-data']);
        $request = new HonoRequest($raw->withParsedBody(['name' => 'Ada'])->withAttribute(
            'php_hono.multipart_parsed',
            true,
        ));
        self::assertSame(['name' => 'Ada'], $request->parseBody());
        $this->expectException(InvalidArgumentException::class);
        $request->parseBody(true);
    }

    public function testBodyReplacementInvalidatesDerivedDataAndKeepsOriginalView(): void
    {
        $raw = new ServerRequest('POST', '/', ['Content-Type' => 'application/json'], '{"name":"Ada"}');
        $request = new HonoRequest($raw);
        $request->setValidated('json', ['name' => 'Ada']);
        $original = $request->json();
        $modified = $request->withRaw($raw->withBody(Stream::create('{"name":"Grace"}')));
        $replacement = $modified->json();
        self::assertInstanceOf(stdClass::class, $replacement);
        self::assertSame('Grace', $replacement->name);
        self::assertSame($original, $request->json());
        self::assertSame(['name' => 'Ada'], $request->valid('json'));
        $this->expectException(LogicException::class);
        $modified->valid('json');
    }

    public function testContentTypeAndParsedBodyChangesInvalidateFormCache(): void
    {
        $raw = new ServerRequest('POST', '/', ['Content-Type' => 'application/x-www-form-urlencoded'], 'name=Ada');
        $request = new HonoRequest($raw);
        self::assertSame(['name' => 'Ada'], $request->parseBody());
        $modified = $request->withRaw(
            $raw
                ->withHeader('Content-Type', 'multipart/form-data')
                ->withParsedBody(['name' => 'Grace'])
                ->withAttribute('php_hono.multipart_parsed', true),
        );
        self::assertSame(['name' => 'Grace'], $modified->parseBody());
    }

    public function testReplacementBodyInheritsLoweredLimit(): void
    {
        $raw = new ServerRequest('POST', '/', [], '123');
        $request = new HonoRequest($raw, bodyStore: new BodyStore($raw->getBody(), 100));
        self::assertSame('123', $request->bodyStore()->bytes(4));
        $modified = $request->withRaw($raw->withBody(Stream::create('12345')));
        $this->expectException(HttpException::class);
        $modified->text();
    }

    public function testValidationDistinguishesMissingFromValidatedNull(): void
    {
        $request = new HonoRequest(new ServerRequest('GET', '/'));
        $request->setValidated('json', null);
        self::assertNull($request->valid('json'));
        $this->expectException(LogicException::class);
        $request->valid('form');
    }

    public function testForwardedHeadersInvalidateRelatedValidatedValuesOnly(): void
    {
        $raw = new ServerRequest('POST', '/', ['Cookie' => 'session=old', 'Content-Type' => 'application/json']);
        $request = new HonoRequest($raw);
        foreach (['header', 'cookie', 'json', 'form', 'param'] as $target) {
            $request->setValidated($target, 'old');
        }
        $forwarded = $request->withRaw($raw->withHeader('Cookie', 'session=new')->withHeader(
            'Content-Type',
            'text/plain',
        ));
        self::assertSame('old', $forwarded->valid('param'));
        foreach (['header', 'cookie', 'json', 'form'] as $target) {
            self::assertSame('old', $request->valid($target));
            try {
                $forwarded->valid($target);
                self::fail('Forwarded data must be validated again.');
            } catch (LogicException $error) {
                self::assertStringContainsString($target, $error->getMessage());
            }
        }
    }
}
