<?php

declare(strict_types=1);

namespace PhpHono\Tests\Unit;

use InvalidArgumentException;
use LogicException;
use PhpHono\HttpException;
use PhpHono\Router\OrderedRouter;
use PhpHono\Router\RouteMatch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Each test describes an independent boundary of the router contract.
 * @mago-expect lint:too-many-methods
 */
final class OrderedRouterTest extends TestCase
{
    public function testAllMatchingHandlersKeepRegistrationOrderAndTheirOwnParameters(): void
    {
        $router = new OrderedRouter();
        $router->add('ALL', '*', static fn(): string => 'global');
        $router->add('GET', '/users/:id', static fn(): string => 'dynamic', ['source' => 'dynamic']);
        $router->add('POST', '/users/me', static fn(): string => 'wrong method');
        $router->add('GET', '/users/me', static fn(): string => 'static');
        $router->add('GET', '/users/me', static fn(): string => 'duplicate');

        $matches = $router->match('GET', '/users/me');

        self::assertSame(
            ['global', 'dynamic', 'static', 'duplicate'],
            array_map(static fn(RouteMatch $match): mixed => ($match->handler)(), $matches),
        );
        self::assertSame([[], ['id' => 'me'], [], []], array_column($matches, 'params'));
        self::assertSame(['source' => 'dynamic'], $matches[1]->metadata);
    }

    /** @return iterable<string, array{string, string, bool, array<string, string>}> */
    public static function paths(): iterable
    {
        yield 'optional absent' => ['/hello/:name?', '/hello', true, []];
        yield 'optional present' => ['/hello/:name?', '/hello/Ada', true, ['name' => 'Ada']];
        yield 'optional empty segment' => ['/hello/:name?', '/hello/', false, []];
        yield 'root optional absent' => ['/:name?', '/', true, []];
        yield 'root optional present' => ['/:name?', '/Ada', true, ['name' => 'Ada']];
        yield 'wildcard base' => ['/api/*', '/api', true, []];
        yield 'wildcard slash' => ['/api/*', '/api/', true, []];
        yield 'wildcard nested' => ['/api/*', '/api/a/b', true, []];
        yield 'wildcard boundary' => ['/api/*', '/api2', false, []];
        yield 'global root' => ['/*', '/', true, []];
        yield 'case sensitive' => ['/about', '/About', false, []];
        yield 'regex digits' => ['/orders/:id{[0-9]{2,4}}', '/orders/123', true, ['id' => '123']];
        yield 'regex rejects' => ['/orders/:id{[0-9]+}', '/orders/abc', false, []];
        yield 'regex noncapturing alternatives' => [
            '/items/:kind{(?:book|film)}',
            '/items/book',
            true,
            ['kind' => 'book'],
        ];
        yield 'encoded slash remains segment' => ['/files/:name', '/files/a%2Fb', true, ['name' => 'a/b']];
        yield 'single decode' => ['/files/:name', '/files/a%252Fb', true, ['name' => 'a%2Fb']];
        yield 'unicode decode' => ['/files/:name', '/files/%D1%82%D0%B5%D1%81%D1%82', true, ['name' => 'тест']];
        yield 'duplicate parameter keeps first' => ['/:id/:id', '/first/second', true, ['id' => 'first']];
    }

    /** @param array<string, string> $params */
    #[DataProvider('paths')]
    public function testSupportedPatterns(string $pattern, string $path, bool $expectedMatch, array $params): void
    {
        $router = new OrderedRouter();
        $router->add('GET', $pattern, static fn(): null => null);
        $matches = $router->match('GET', $path);
        self::assertCount($expectedMatch ? 1 : 0, $matches);
        if ($expectedMatch) {
            self::assertSame($params, $matches[0]->params);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedPatterns(): iterable
    {
        foreach ([
            'relative',
            '/a/*/b',
            '/assets*',
            '/:a?/:b?',
            '/:a?/b',
            '/:id{(a)}',
            '/:id{(?=a)a}',
            '/:id{(a)\\1}',
            '/:id{.+}',
            '/:id{[^a]+}',
            '/:id{[z-a]}',
            '/:id{a/b}',
            '/:id{a{no}}',
            '/:id{(?:a}',
            '/foo-:id',
            '/x?query',
            '/:id{\\p{L}+}',
        ] as $path) {
            yield $path => [$path];
        }
    }

    #[DataProvider('unsupportedPatterns')]
    public function testUnsupportedPatternsFailAtRegistration(string $path): void
    {
        $router = new OrderedRouter();
        $this->expectException(InvalidArgumentException::class);
        $router->add('GET', $path, static fn(): null => null);
    }

    public function testStrictFalseRemovesOnlyOneTrailingSlashFromInput(): void
    {
        $strict = new OrderedRouter();
        $strict->add('GET', '/a', static fn(): null => null);
        self::assertCount(0, $strict->match('GET', '/a/'));

        $loose = new OrderedRouter(strict: false);
        $loose->add('GET', '/a', static fn(): null => null);
        self::assertCount(1, $loose->match('GET', '/a/'));
        self::assertCount(0, $loose->match('GET', '/a//'));
    }

    public function testFirstMatchFreezesRegistrationEvenWhenNothingMatches(): void
    {
        $router = new OrderedRouter();
        self::assertSame([], $router->match('GET', '/missing'));
        self::assertTrue($router->isFrozen());
        $this->expectException(LogicException::class);
        $router->add('GET', '/later', static fn(): null => null);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedPaths(): iterable
    {
        yield 'invalid escape' => ['/bad%xx'];
        yield 'truncated escape' => ['/bad%2'];
        yield 'invalid UTF-8' => ['/bad%FF'];
        yield 'encoded NUL' => ['/bad%00'];
    }

    #[DataProvider('malformedPaths')]
    public function testMalformedPathIsBadRequest(string $path): void
    {
        $router = new OrderedRouter();
        $this->expectException(HttpException::class);
        $router->match('GET', $path);
    }

    public function testPathLengthIsBounded(): void
    {
        $router = new OrderedRouter(pathLimit: 8);
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('URI Too Long');
        $router->match('GET', '/12345678');
    }

    public function testPcreResourceFailureIsNotReportedAsMissingRoute(): void
    {
        $router = new OrderedRouter(matchLimit: 20);
        $router->add('GET', '/:value{(?:a|aa)+b}', static fn(): null => null);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Route matching failed');
        $router->match('GET', '/' . str_repeat('a', times: 100) . 'b');
    }
}
