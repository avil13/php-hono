# php-hono

**English** | [Русский](README.ru.md)

An HTTP framework for **PHP 8.5** with a [Hono](https://github.com/honojs/hono)-style API, PSR-7 responses, and support for third-party PSR-15 middleware. The selected 1.0 feature set is implemented: ordered routing, Context extensions, validation, HTTP middleware, streaming/SSE, SAPI/FPM, and a FrankenPHP worker adapter.

This is an independent implementation, not an official port. JSX and WebSocket are excluded. See the [compatibility guide](docs/en/compatibility.md) for the supported scope.

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use PhpHono\Context;
use PhpHono\Hono;
use PhpHono\Middleware\Psr15;

$app = new Hono();
$app->get('/hello/:name', fn (Context $c) =>
    $c->json(['hello' => $c->req->param('name')])
);

$response = $app->request('/hello/Ada');
echo $response->getBody(); // {"hello":"Ada"}
```

From a repository checkout:

```sh
composer install
composer tools:prepare
composer check
composer hooks:install
php -S 127.0.0.1:8080 -t examples/public examples/public/index.php
curl http://127.0.0.1:8080/hello/Ada
```

The package is prepared under the name `php-hono/php-hono`, with the `PhpHono` namespace. It has not been published to Packagist or tagged for release. Until publication, use a checkout or a local Composer path/artifact repository as described in the [installation guide](docs/en/getting-started.md).

- [Documentation](docs/en/README.md), [API reference](docs/en/api.md), [built-in middleware](docs/en/builtin-middleware.md).
- [PSR-15 bridge](docs/en/middleware.md#psr-15-middleware), [Context and DI](docs/en/context-extensions.md).
- [Deployment and streaming](docs/en/deployment.md), [development and pre-commit checks](docs/en/development.md).
- [Implementation plan and status (Russian)](spec/implementation-status.md), [benchmarks](benchmarks/README.md).

Checks use Mago **1.59.0** for formatting and strict linting, PHPStan at **max** level, and PHPUnit. Offline fixtures are generated from real Hono **4.13.16** at a pinned commit. Node is needed only to regenerate fixtures and run auxiliary HTTP checks, not to use the library.

Licensed under [MIT](LICENSE). Upstream attribution is recorded in [THIRD_PARTY_NOTICES](THIRD_PARTY_NOTICES.md).
