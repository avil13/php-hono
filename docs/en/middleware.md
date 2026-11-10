# Middleware

**English** | [Русский](../ru/middleware.md)

Middleware in php-hono 1.0 for PHP 8.5+. [Documentation contents](overview.md).

Middleware performs shared request processing: it can validate data, pass control to the next handler, modify the response or end the request early.

## Code before and after a handler

```php
use PhpHono\Context;
use PhpHono\Hono;
use Psr\Http\Message\ResponseInterface;

$app = new Hono();
$app->use('*', function (Context $c, callable $next): void {
    $startedAt = hrtime(true);

    $next();

    $milliseconds = (hrtime(true) - $startedAt) / 1_000_000;
    $c->header('X-Response-Time', sprintf('%.3fms', $milliseconds));
});

$app->get('/hello', fn (Context $c): ResponseInterface => $c->text('Hello'));
```

`$next()` synchronously runs the rest of the chain. After it returns, the response is available through `$c->response()`. There is no `async`/`await`. In the examples below, `Context`, `ResponseInterface` and `$app` refer to the imports and instance from this example.

For middleware A, then B, then an endpoint, the order is:

```text
A: before next
  B: before next
    endpoint
  B: after next
A: after next
```

Register outer middleware before inner middleware. For example, timing middleware registered first measures the entire remaining chain.

## Scope

```php
$app->use('/api/*', function (Context $c, callable $next): void {
    $c->header('X-API-Version', '1');
    $next();
});

$app->get('/api/status', fn (Context $c): ResponseInterface =>
    $c->json(['ok' => true])
);
```

`use('*', ...)` applies to all paths, while `use('/api/*', ...)` applies to `/api` and its nested paths. Middleware and routes share the same registration order. If an earlier handler returns a response without calling `$next()`, later middleware does not run.

Place shared middleware before routes if it also needs to modify 404 and error responses. Pattern and grouping rules are covered in [routing](routing.md).

## Early responses

Middleware can return a response without calling `$next()`:

```php
$requireClientVersion = function (Context $c, callable $next): ?ResponseInterface {
    if ($c->req->header('X-Client-Version') === null) {
        return $c->json(['error' => 'X-Client-Version is required'], 400);
    }

    $next();
    return null;
};

$app->post('/events', $requireClientVersion,
    fn (Context $c): ResponseInterface => $c->json(['accepted' => true], 202)
);
```

The endpoint does not run when the header is absent. This example checks an input requirement; it is not an authentication mechanism. Multiple handlers on a route act as a sequence of middleware followed by the endpoint handler.

## Passing data through Context

Use `set/get/has` for data belonging to the current request:

```php
$app->use('/api/*', function (Context $c, callable $next): void {
    $c->set('locale', $c->req->query('lang') ?? 'en');
    $next();
});

$app->get('/api/preferences', fn (Context $c): ResponseInterface =>
    $c->json(['locale' => $c->get('locale')])
);
```

Each request gets a new Context. Do not store the current user or other request data in static properties or shared mutable closures: those values outlive the request in a persistent worker. `has()` distinguishes a missing key from a key whose value is `null`.

For typed state, middleware writes `set(AuthContext::class, $auth)`, and the next handler reads `extension(AuthContext::class)`. Keys must match exactly, and the object must have the requested type; the method does not invoke a factory or autowiring. Create request state inside middleware, and pass the callable's own dependencies through its constructor. See the [Context extensions and DI guide](context-extensions.md).

## Modifying the response after next

Use `$c->header()` for headers. To replace the body or status, set a new response explicitly:

```php
$app->use('/legacy', function (Context $c, callable $next): void {
    $next();

    $response = $c->response();
    if ($response !== null && $response->getStatusCode() === 404) {
        $c->setResponse($c->json(['error' => 'Legacy resource not found'], 404));
    }
});
```

After `$next()`, simply writing `return $c->json(...)` does not replace a finalized response. Use `setResponse()` instead. Calling `text/json/html/body` only creates a Response; it must be returned or explicitly set before finalization.

Response replacement follows header inheritance rules: headers describing the old body are not carried over, while other missing headers may be inherited. To remove a header, use `$c->header('Header-Name', null)`. The full rules, including Set-Cookie behavior, are in [Context and responses](context-response.md).

## Middleware errors

A downstream exception is handled through `onError` before `$next()` returns. Code in outer middleware after `$next()` can therefore add headers to the error response. An exception thrown by the middleware itself after `$next()` also reaches `onError`.

Returning from `$next()` consequently does not prove that a business operation succeeded. Do not commit a transaction unconditionally afterwards; complete it based on an explicit service result, and clean up or roll back unfinished work in `finally`. See the [lifecycle example](context-extensions.md#lifecycle-and-resource-cleanup).

Two scenarios are invalid:

- Calling `$next()` again from the same middleware: the remaining chain cannot run twice.
- Finishing without `$next()`, a returned Response or `setResponse()`: this is a developer error that becomes a 500 response.

Remember to `return` the response factory result. `void` is appropriate for middleware that calls `$next()` and modifies Context. If middleware can return an early Response, use an appropriate return type, such as `?ResponseInterface`, and return `null` in the continuation branch, as above.

## Built-in middleware

Middleware classes in `PhpHono\Middleware` are ready-to-use invokable objects. Pass them to `use()` or a route's handler list.

```php
use PhpHono\Middleware\BodyLimit;
use PhpHono\Middleware\Cors;
use PhpHono\Middleware\SecureHeaders;

$app->use('*', new SecureHeaders());
$app->use('/api/*', new Cors(origin: 'https://app.example.com'));
$app->use('/api/*', new BodyLimit(maxBytes: 1_048_576));
```

Place these calls before registering endpoints. CORS must wrap auth/CSRF if preflight should finish before credentials are checked.

| Feature | Implemented API |
| --- | --- |
| Validator | `new Validator($target, $validate)`; read the result through `req->valid()` |
| Body limit | `new BodyLimit($maxBytes)` before reading the body |
| CORS, secure headers | `new Cors(...)`, `new SecureHeaders(...)` |
| Request ID, logger | `new RequestId(...)`, `new Logger(...)` |
| Cookies | `PhpHono\Helper\Cookie`; a helper requiring no middleware |
| Basic/Bearer auth | `BasicAuth::credentials(...)`, `BearerAuth::token(...)` or callback constructors |
| CSRF | `new Csrf(origins: [...])` |
| ETag and gzip | `new ETag()`, followed by `new Compress()` |
| 405 and slash policy | `new MethodNotAllowed($app)`, `new TrailingSlash(...)` |
| Static files | `new StaticFiles($root, $prefix)` |

Signatures, defaults and examples for the core features are in the [built-in middleware guide](builtin-middleware.md); auth, CSRF, ETag, gzip and files are covered in [HTTP middleware 1.0](advanced-middleware.md).

For multipart requests, PHP has already done part of the processing before middleware runs. The framework's body limit therefore does not replace `post_max_size`, upload limits or web server limits. See [Request](request.md) and [deployment](deployment.md) for details.

## PSR-15 middleware

**Existing third-party PSR-15 middleware is supported through the bridge.** API: `PhpHono\Middleware\Psr15::wrap(MiddlewareInterface $middleware): callable`. It lets you use the original object from a Composer package without rewriting `process()` to work with Context.

For example, [middlewares/response-time](https://github.com/middlewares/response-time) adds processing time to a response header. After installing the Composer package, register its object:

```php
use Middlewares\ResponseTime;
use PhpHono\Context;
use PhpHono\Hono;
use PhpHono\Middleware\Psr15;

$app = new Hono();
$app->use('*', Psr15::wrap(new ResponseTime()));
$app->get('/health', fn (Context $c) => $c->json(['ok' => true]));
```

For a single route, pass the result of `wrap()` among its handlers: `$app->get('/health', Psr15::wrap($middleware), $handler)`. Hono callables and PSR middleware share the same registration order.

The bridge forwards changed request headers, body and attributes downstream, allows an early Response, and preserves the returned PSR response exactly, including removed headers and Set-Cookie values. Available raw body bytes are exposed for repeated reading through the shared bounded cache. This also works after a previous Hono reader, but may require buffering. The application's body limit remains in force.

PSR attributes do not become Context extensions automatically. Place a mapper after the PSR middleware: it reads the current `$c->req->raw()` and explicitly stores an object with `set(Type::class, $object)`. Restoring the upstream request view does not roll back Context variables; they are shared within the current request. See the [mapper example](context-extensions.md#psr-15-attributes-and-typed-extensions).

The limits of this profile are:

- Inside the bridge, the route has already been selected, so changing the URI or HTTP method is forbidden. Use an external PSR-15 pipeline **before** the App for that kind of middleware. A single middleware object uses the standard `$middleware->process($request, $app)` call; no custom php-hono dispatcher is required.
- Downstream can be called only once. Retry middleware that calls `handle()` repeatedly is not supported by this bridge.
- Endpoint exceptions have already been converted to a Response by Hono's `onError`. A third-party exception handler does not replace that mechanism; an outer pipeline handles errors in its own components outside the App.
- Laravel/Symfony middleware with framework-specific interfaces and the old double-pass API do not automatically become PSR-15-compatible.

`composer.lock` pins the integration test versions of [middlewares/uuid](https://github.com/middlewares/uuid) at 2.1.0 and [middlewares/response-time](https://github.com/middlewares/response-time) at 2.1.0. Integration tests use their real classes; separate contract tests cover body handling, attributes, errors and mixed middleware ordering. The core does not need these packages, and they are not added to production runtime dependencies automatically.

Next: [Request](request.md), [error handling](errors.md), [testing](testing.md).
