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

- [Documentation](docs/en/overview.md), [API reference](docs/en/api.md), [built-in middleware](docs/en/builtin-middleware.md).
- [PSR-15 bridge](docs/en/middleware.md#psr-15-middleware), [Context and DI](docs/en/context-extensions.md).
- [Deployment and streaming](docs/en/deployment.md), [development and pre-commit checks](docs/en/development.md).
- [Implementation plan and status (Russian)](spec/implementation-status.md), [benchmarks](benchmarks/README.md).

The VitePress documentation site is configured for [GitHub Pages](https://avil13.github.io/php-hono/), with English as the primary language and a Russian translation. To preview it locally with Node.js 24 LTS:

```sh
npm ci
npm run docs:dev
```

For a production build, run `npm run docs:build`, then `npm run docs:preview`. See [documentation publishing](docs/en/development.md#documentation-site-and-github-pages) for the GitHub Actions workflow and first-deployment setup.

Checks use Mago **1.59.0** for formatting and strict linting, PHPStan at **max** level, and PHPUnit. Offline fixtures are generated from real Hono **4.13.16** at a pinned commit. Node is used for the documentation site, fixture regeneration, and auxiliary HTTP checks; the PHP library does not require it.

Licensed under [MIT](LICENSE). Upstream attribution is recorded in [THIRD_PARTY_NOTICES](THIRD_PARTY_NOTICES.md).
