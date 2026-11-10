# Getting started

**English** | [Русский](../ru/getting-started.md)

[Contents](README.md) · Implemented API 1.0, PHP 8.5.

## Requirements and installation

You need **PHP 8.5+** and Composer. HTTP objects use PSR-7/17, with `nyholm/psr7` as the default implementation. Application tests use PHPUnit. You do not need Node.js to use the PHP framework.

The package is named `php-hono/php-hono`; it has not been published externally yet. From a repository checkout, run:

```sh
composer install
composer tools:prepare
composer check
php -S 127.0.0.1:8080 -t examples/public examples/public/index.php
```

To use the unpublished package in a separate application, add the local checkout as a path repository:

```json
{
  "repositories": [{
    "type": "path",
    "url": "/absolute/path/to/php-hono",
    "options": {"versions": {"php-hono/php-hono": "1.0.0"}}
  }],
  "require": {"php": "^8.5", "php-hono/php-hono": "^1.0"}
}
```

Run `composer install` in the application directory. The path version is for the local Composer repository; it does not indicate a published release. Runtime dependencies include Nyholm and the PSR interfaces. Mago, PHPUnit and PHPStan are development tools for the framework itself.

## A small application structure

```text
my-api/
  app.php
  public/
    index.php
  tests/
    AppTest.php
  vendor/
```

`app.php` registers routes and returns the application. `public/index.php` connects it to the server. This separation lets HTTP requests and tests use the same routes. The front controller is:

```php
<?php
declare(strict_types=1);
$app = require dirname(__DIR__) . '/app.php';
\PhpHono\Adapter\Sapi::run($app);
```

FPM and worker configuration is covered in [deployment](deployment.md).

## Your first application

Contents of `app.php`:

```php
<?php

declare(strict_types=1);

use PhpHono\Context;
use PhpHono\Hono;
use Psr\Http\Message\ResponseInterface;

require __DIR__ . '/vendor/autoload.php';

$app = new Hono();

$app->get('/', fn (Context $c): ResponseInterface =>
    $c->text('Hello, PHP Hono!')
);

$app->get('/hello/:name', fn (Context $c): ResponseInterface =>
    $c->json(['message' => 'Hello, ' . $c->req->param('name') . '!'])
);

$app->post('/echo', fn (Context $c): ResponseInterface =>
    $c->json($c->req->json())
);

return $app;
```

A handler is a PHP callable. `$c->req` contains the incoming request, while `$c->text()` and `$c->json()` create a response. You must **return** the response: calling `$c->text('OK')` without `return` does not complete request handling correctly.

An incoming JSON object is decoded into `stdClass`; a JSON array becomes a PHP array. The `/echo` example returns that value without converting it.

## Checking the application without a server

Example `check.php` next to `app.php`:

```php
<?php

declare(strict_types=1);

$app = require __DIR__ . '/app.php';

$response = $app->request('/hello/Ada');

printf("Status: %d\n", $response->getStatusCode());
printf("Body: %s\n", (string) $response->getBody());
```

The expected result is status 200 and JSON with a `message` field equal to `Hello, Ada!`. `request()` does not open a connection or start a web server.

For POST, provide a method, headers and a string body:

```php
$response = $app->request('/echo', [
    'method' => 'POST',
    'headers' => ['Content-Type' => 'application/json'],
    'body' => json_encode(['name' => 'Ada'], JSON_THROW_ON_ERROR),
]);
```

Use only the supported options: `method`, `headers` and `body`. This helper has no HTTP client options such as `timeout` or `followRedirects`.

## Adding middleware

Register middleware **before** the routes it should apply to:

```php
use PhpHono\Context;
use PhpHono\Hono;

$app = new Hono();

$app->use('*', function (Context $c, callable $next): void {
    $next();
    $c->header('X-App', 'php-hono');
});

$app->get('/', fn (Context $c) => $c->text('OK'));
```

Code after `$next()` works with the response prepared downstream. Here it adds a header to the route's response.

## Next steps

Group applications with [route/basePath](routing.md) when you have several endpoints. JSON and form reading are covered in [Request](request.md), and error handling in [Errors](errors.md). Before connecting a server, write [tests using request()](testing.md): they check the application without network infrastructure.
