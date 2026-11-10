# PHP Hono

**English** | [Русский](../ru/overview.md)

PHP Hono is an HTTP framework for **PHP 8.5+** with Hono-style routing and middleware. A handler receives a Context and returns a PSR-7 Response. You can call the application in memory or connect it to an HTTP server through an adapter.

**Status: the selected 1.0 feature set is implemented.** The package name is `php-hono/php-hono`, and the namespace is `PhpHono`. The repository contains source code, examples, strict checks, a PSR-15 bridge, middleware, streaming, and a worker adapter. The package has not been published. See the [task status and verification report (Russian)](https://github.com/avil13/php-hono/blob/master/spec/implementation-status.md).

```php
use PhpHono\Context;
use PhpHono\Hono;

$app = new Hono();
$app->get('/', fn (Context $c) => $c->text('Hello, PHP Hono!'));
$app->get('/users/:id', fn (Context $c) =>
    $c->json(['id' => $c->req->param('id')])
);
```

## Guides

| Guide | What it covers |
| --- | --- |
| [Getting started](getting-started.md) | Requirements, application structure, your first API, and in-memory requests |
| [Routing](routing.md) | HTTP methods, parameters, wildcards, groups, and registration order |
| [Middleware](middleware.md) | Code before and after a handler, early responses, Context, and built-in features |
| [Request](request.md) | Query strings, headers, JSON, forms, and file uploads |
| [Context and responses](context-response.md) | Text/JSON/HTML, status codes, headers, cookies, and response replacement |
| [Context extensions and dependencies](context-extensions.md) | Typed request state, constructor DI, bootstrap containers, and lifecycle |
| [Errors](errors.md) | 404 responses, onError, HTTP errors, and diagnostics |
| [Testing](testing.md) | PHPUnit, in-memory requests, datasets, and isolation checks |
| [Development and checks](development.md) | Mago, strict linting, and pre-commit checks |
| [Deployment](deployment.md) | PSR-7 input, SAPI/FPM, the local server, FrankenPHP workers, and SSE |
| [Compatibility](compatibility.md) | Differences from JavaScript Hono, the implemented profile, and limitations |

Start with [your first application](getting-started.md), then read about [middleware order](middleware.md). It determines which handlers run and which response reaches the client.

## Core rules

- Every request gets a new Context. Store request data through `set/get/has`; retrieve typed objects through `extension(Type::class)`, not global variables.
- Assemble handlers and middleware with constructor DI. An optional PSR-11 container is used only during bootstrap. The core has no autowiring or service locator.
- A handler returns a Response. Middleware either returns an early Response or calls `$next()` exactly once.
- Finish registering routes before the first request. The route table freezes when dispatch starts.
- `request()` runs the application in memory. `handle()` accepts a PSR-7 ServerRequest. Neither method sends the response over the network.
- Third-party PSR-15 middleware is connected through `Psr15::wrap()`; see the [example and limitations](middleware.md#psr-15-middleware).
- JSX and WebSocket are excluded from the project. HTML responses and HTTP streaming/SSE are supported.

## Reference

- [Public API](api.md).
- [Built-in middleware and cookies](builtin-middleware.md).
- [Auth, CSRF, ETag, gzip, files, 405, and trailing slashes](advanced-middleware.md).
- [Moving from the 0.1 design to 1.0](migration.md).
- [Benchmark baseline](https://github.com/avil13/php-hono/blob/master/benchmarks/README.md).

The [specification](https://github.com/avil13/php-hono/blob/master/spec/02-specification.md) and [implementation status](https://github.com/avil13/php-hono/blob/master/spec/implementation-status.md) record the project decisions in Russian. This library does not claim to support every Hono export.
