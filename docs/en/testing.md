# Testing applications

**English** | [Русский](../ru/testing.md)

[Contents](README.md) · Testing applications and the implemented API 1.0.

Formatting/linting, PHPStan and pre-commit are part of the shared [development checks](development.md). The hook checks staged PHP code with Mago; CI runs the complete test suite and checks dependencies against the lockfile.

## Tools

The main tool is PHPUnit 12.5. Its assertions and data providers suit HTTP response and route table tests. The exact version is pinned in composer.lock; there is no separate required php-hono test runner. The standard test format follows the [PHPUnit documentation](https://docs.phpunit.de/en/12.5/writing-tests-for-phpunit.html).

Framework installation is described in [getting started](getting-started.md). After installing PHPUnit, run a test file with:

```sh
vendor/bin/phpunit --bootstrap vendor/autoload.php tests/AppTest.php
```

In this repository, `composer test` runs the whole suite; `composer check` adds Mago and PHPStan max. swew/test is not used: PHPUnit covers the current contract, integration and data-provider scenarios without another runner.

## Testing a route

A complete `tests/AppTest.php` example:

```php
<?php

declare(strict_types=1);

use PhpHono\Context;
use PhpHono\Hono;
use PHPUnit\Framework\TestCase;

final class AppTest extends TestCase
{
    public function testUserRouteReturnsJson(): void
    {
        $app = new Hono();
        $app->get('/users/:id', fn (Context $c) =>
            $c->json(['id' => $c->req->param('id')])
        );

        $response = $app->request('/users/42');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            ['id' => '42'],
            json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testHeadUsesGetRouteWithoutResponseBody(): void
    {
        $app = new Hono();
        $app->get('/', fn (Context $c) =>
            $c->text('Hello', 200, ['X-Method' => $c->req->method()])
        );

        $response = $app->request('/', ['method' => 'HEAD']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('HEAD', $response->getHeaderLine('X-Method'));
        self::assertSame('', (string) $response->getBody());
    }

    public function testMalformedJsonReturnsBadRequest(): void
    {
        $app = new Hono();
        $app->post('/echo', fn (Context $c) => $c->json($c->req->json()));

        $response = $app->request('/echo', [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'body' => '{',
        ]);

        self::assertSame(400, $response->getStatusCode());
    }
}
```

Compare JSON as a data structure when testing API data. Check bytes for text, binary bodies and HTML formatting. Check multiple Set-Cookie values with `getHeader('Set-Cookie')`, not the joined `getHeaderLine()` string.

## One dataset, several scenarios

A separate `RouteTest` class example:

```php
<?php

declare(strict_types=1);

use PhpHono\Context;
use PhpHono\Hono;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RouteTest extends TestCase
{
    public static function paths(): iterable
    {
        yield 'exact' => ['/users/42', 200];
        yield 'strict trailing slash' => ['/users/42/', 404];
        yield 'missing segment' => ['/users', 404];
    }

    #[DataProvider('paths')]
    public function testPath(string $path, int $expectedStatus): void
    {
        $app = new Hono();
        $app->get('/users/:id', fn (Context $c) => $c->text('Found'));

        self::assertSame($expectedStatus, $app->request($path)->getStatusCode());
    }
}
```

Register all routes before the first `request()`. Adding routes between requests on the same instance is not supported: its table is already frozen.

## Checking state between requests

You can add this method to a PHPUnit `TestCase`. It deliberately uses one application instance:

```php
public function testContextDoesNotLeakBetweenRequests(): void
{
    $app = new \PhpHono\Hono();
    $app->get('/', function (\PhpHono\Context $c) {
        $wasPresent = $c->has('visited');
        $c->set('visited', true);

        return $c->json(['wasPresent' => $wasPresent]);
    });

    foreach ([1, 2] as $requestNumber) {
        $response = $app->request('/');
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse($data['wasPresent'], 'Request ' . $requestNumber);
    }
}
```

This checks Context isolation. Data that the application stores in a mutable closure, singleton or static property is not cleared automatically.

## When a real server is needed

`request()` tests routes, middleware and HTTP messages, but not FPM behavior or sending bytes to a client. Test SAPI uploads, PHP/web server size limits, multiple Set-Cookie values in HTTP responses and the absence of a HEAD body separately. The repository includes real worker/streaming checks: state cleanup after errors, chunks arriving before the producer finishes, and client disconnection.

An in-memory multipart test must pass a prepared PSR request and a marker confirming successful parsing; a raw multipart body is not parsed on its own. See [Request](request.md) for details.

The framework is also tested against reference scenarios from the pinned Hono 4.13.16 baseline. Applications do not need that JavaScript harness. Matching a few responses does not establish support for every Hono API — consult the [compatibility matrix](compatibility.md).
