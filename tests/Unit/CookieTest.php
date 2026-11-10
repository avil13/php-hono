<?php

declare(strict_types=1);

namespace PhpHono\Tests\Unit;

use DateTimeImmutable;
use InvalidArgumentException;
use PhpHono\Context;
use PhpHono\Helper\Cookie;
use PhpHono\Hono;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class CookieTest extends TestCase
{
    public function testParserPreservesFirstDuplicateAndDecodesSafely(): void
    {
        self::assertSame(
            [
                'name' => 'Alice B',
                'quoted' => 'two words',
                'plus' => 'a+b',
                'colon:key' => 'yes',
                'bad' => '%GG',
                'bytes' => '%FF',
                'empty' => '',
            ],
            Cookie::parse(
                'name=Alice%20B; name=ignored; quoted="two words"; plus=a+b; colon:key=yes; bad=%GG; bytes=%FF; empty=; missing; bad name=no',
            ),
        );
    }

    public function testSerializerEscapesValuesAndSupportsAttributes(): void
    {
        $serialized = Cookie::serialize('session', 'привет; x=1', [
            'path' => '/app',
            'domain' => '.example.test',
            'expires' => new DateTimeImmutable('2026-01-01T03:00:00+03:00'),
            'maxAge' => 3600,
            'secure' => true,
            'httpOnly' => true,
            'sameSite' => 'none',
        ]);
        self::assertSame(
            'session=%D0%BF%D1%80%D0%B8%D0%B2%D0%B5%D1%82%3B%20x%3D1; Max-Age=3600; Domain=.example.test; Path=/app; Expires=Thu, 01 Jan 2026 00:00:00 GMT; HttpOnly; Secure; SameSite=None',
            $serialized,
        );
        self::assertSame('привет; x=1', Cookie::parse(explode(';', $serialized, limit: 2)[0])['session']);
        self::assertSame('__Host-session=safe; Path=/; Secure', Cookie::serialize('__Host-session', 'safe', [
            'secure' => true,
        ]));
    }

    public function testCookiesAppendBeforeAndAfterNextAndDeleteWithMatchingScope(): void
    {
        $app = new Hono();
        $app->use('*', static function (Context $c, callable $next): void {
            Cookie::set($c, 'before', '1');
            $next();
            Cookie::set($c, 'after', '2');
            Cookie::delete($c, 'old', ['path' => '/app', 'domain' => 'example.test']);
        });
        $app->get('/', static fn(Context $c): ResponseInterface => $c->json(
            [Cookie::get($c, 'incoming'), Cookie::get($c, 'missing')],
            200,
            ['Set-Cookie' => 'handler=3'],
        ));
        $response = $app->request('/', ['headers' => ['Cookie' => 'incoming=hello%20world']]);
        self::assertSame('["hello world",null]', (string) $response->getBody());
        self::assertSame(
            [
                'before=1; Path=/',
                'handler=3',
                'after=2; Path=/',
                'old=; Max-Age=0; Domain=example.test; Path=/app; Expires=Thu, 01 Jan 1970 00:00:00 GMT',
            ],
            $response->getHeader('Set-Cookie'),
        );
    }

    /** @param array{domain?: string, path?: string, maxAge?: int, secure?: bool, sameSite?: string} $options */
    #[DataProvider('invalidOptions')]
    public function testInvalidNamesAttributesAndSecurityCombinationsAreRejected(string $name, array $options): void
    {
        $this->expectException(InvalidArgumentException::class);
        Cookie::serialize($name, 'safe', $options);
    }

    /** @return iterable<string, array{string, array{domain?: string, path?: string, maxAge?: int, secure?: bool, sameSite?: string}}> */
    public static function invalidOptions(): iterable
    {
        yield 'name injection' => ["bad\r\nSet-Cookie", []];
        yield 'path injection' => ['name', ['path' => '/; Secure']];
        yield 'domain injection' => ['name', ['domain' => "example.test\r\nX-Injected: yes"]];
        yield 'invalid same site' => ['name', ['sameSite' => 'Whatever']];
        yield 'same site none without secure' => ['name', ['sameSite' => 'None']];
        yield 'secure prefix without secure' => ['__Secure-name', []];
        yield 'host prefix with path' => ['__Host-name', ['secure' => true, 'path' => '/app']];
        yield 'host prefix with domain' => ['__Host-name', ['secure' => true, 'domain' => 'example.test']];
        yield 'negative max age' => ['name', ['maxAge' => -1]];
    }
}
