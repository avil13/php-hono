# Routing

**English** | [Русский](../ru/routing.md)

Routing in php-hono 1.0 for PHP 8.5+. [Documentation contents](README.md).

## Your first route

A route maps an HTTP method and path to a handler. The handler receives a Context and returns a PSR-7 Response.

```php
use PhpHono\Context;
use PhpHono\Hono;
use Psr\Http\Message\ResponseInterface;

$app = new Hono();
$app->get('/hello', fn (Context $c): ResponseInterface => $c->text('Hello'));
```

The sections below are independent examples, not a single file to copy in sequence. In them, `$app` means a new Hono instance, and `Context` and `ResponseInterface` use the imports from the first example. Register all routes before handling the first request.

## HTTP methods

Common methods have `get`, `post`, `put`, `patch`, `delete` and `options` helpers. The `query` method registers the HTTP QUERY method; read the query string through `$c->req->query()`.

```php
$app->post('/messages', fn (Context $c): ResponseInterface =>
    $c->json(['accepted' => true], 202)
);

$app->on(['GET', 'POST'], ['/ping', '/health'],
    fn (Context $c): ResponseInterface => $c->text('ok')
);

$app->all('/maintenance', fn (Context $c): ResponseInterface =>
    $c->text('Maintenance', 503)
);
```

`on()` accepts a string or an array of methods and paths. Method names are converted to uppercase. `all()` registers a handler for any method. Every call requires an explicit path: the PHP API has no form that reuses the path from the previous call.

## Path parameters

```php
$app->get('/users/:id', fn (Context $c): ResponseInterface =>
    $c->json(['id' => $c->req->param('id')])
);

$app->get('/hello/:name?', fn (Context $c): ResponseInterface =>
    $c->text('Hello, ' . ($c->req->param('name') ?? 'guest'))
);

$app->get('/orders/:id{[0-9]+}', fn (Context $c): ResponseInterface =>
    $c->json(['orderId' => $c->req->param('id')])
);
```

For `/users/42`, the `id` parameter is the string `'42'`. Validate and convert types at the application boundary. `$c->req->param()` without a name returns the parameter map for the currently matched handler; a missing individual parameter returns `null`.

Only the final segment can be optional. Regex applies within one segment: `[0-9]+` is supported, while crossing `/`, lookaround and backreferences are not. If a name is repeated, as in `/:id/:id`, the first value is retained; different names are clearer.

Parameters are decoded after route matching. `%2F` does not become a segment boundary before matching. Do not decode parameters a second time in the handler. See [Request](request.md) for details.

## Supported patterns

| Pattern | Matches |
| --- | --- |
| `/` | The root path only |
| `/about` | The exact, case-sensitive path |
| `/users/:id` | One required segment |
| `/hello/:name?` | `/hello` and `/hello/Ada` |
| `/orders/:id{[0-9]+}` | A numeric segment |
| `*` or `/*` | Any path |
| `/api/*` | `/api`, `/api/`, `/api/users`; not `/api2` |

Version 1.0 does not support wildcards inside paths (`/a/*/b`), suffix wildcards (`/assets*`), multiple optional segments or regex spanning several segments. An unsupported pattern throws `InvalidArgumentException` during registration.

Regex supports character classes that do not match `/`, `\d`, `\w`, `\s`, numeric quantifiers and `(?:...)` groups. The wildcard `.`, anchors, capturing groups, Unicode properties and a literal `/` inside an expression, including `[^/]`, are not supported. Use a pattern such as `[A-Za-z0-9_-]+`.

The query string does not affect route selection: `/search?q=php` is matched against `/search`. By default, `/about` and `/about/` are distinct (`strict=true`). `new Hono(strict: false)` removes one trailing `/` from the incoming path, except for the root, without redirecting. It normalizes only the incoming path: register `/about`, not `/about/`.

The default path limit is 8192 bytes (`new Hono(maxPathLength: 8192)`); exceeding it returns 414. Malformed percent encoding or UTF-8 in the path/query returns 400.

## Registration order

Matching routes and middleware run in registration order. A static path does not automatically take priority over a parameterized path.

```php
$app = new Hono();

$app->get('/users/new', fn (Context $c): ResponseInterface =>
    $c->text('New user form')
);

$app->get('/users/:id', fn (Context $c): ResponseInterface =>
    $c->text('User ' . $c->req->param('id'))
);
```

For `/users/new`, the first handler returns a response and ends the chain. Reversing the declarations makes the parameterized handler return `User new`. To pass control to the next match, call `$next()` — see [middleware](middleware.md).

Each handler sees the parameters of its own pattern, including after `$next()` returns. This intentionally differs from Hono 4.13.16, which keeps the parameters of the last downstream route active. Global middleware registered on `'*'` does not receive the next endpoint's `:id` parameter in advance.

## Route groups

`route()` adds a child application's already registered routes under a prefix:

```php
$users = new \PhpHono\Hono();
$users->get('/', fn (Context $c): ResponseInterface => $c->json([]));
$users->get('/:id', fn (Context $c): ResponseInterface =>
    $c->json(['id' => $c->req->param('id')])
);

$app->route('/users', $users);
```

Finish configuring `$users` before calling `route()`. Routes added to the child later do not appear in the parent automatically. The child's `onError` is preserved during import, while `notFound` uses the root application's handler.

`basePath()` creates a prefixed view of the shared route table:

```php
$api = $app->basePath('/api');
$api->get('/status', fn (Context $c): ResponseInterface => $c->json(['ok' => true]));
$app->get('/status', fn (Context $c): ResponseInterface => $c->text('ok'));
```

This registers `/api/status` and `/status`. The original `$app` keeps its prefix. The table is shared: the first dispatch through any view closes it to further registration.

## HEAD, OPTIONS and method misses

HEAD uses the GET chain, preserves `$c->req->method() === 'HEAD'` inside the handler, and returns a response without a body. A separate `on('HEAD', ...)` handler is not selected: this is the chosen Hono v4 behavior. Do not make data changes in GET handlers on the assumption that HEAD will not call them.

If a path exists only for GET, a POST to it returns 404 by default. To opt into a 405 response, register `new \PhpHono\Middleware\MethodNotAllowed($app)` before the routes; see the [example](advanced-middleware.md#405-and-trailing-slashes). Register OPTIONS explicitly or let CORS middleware handle it; the core does not create a universal OPTIONS response.

Custom 404 responses are covered in [error handling](errors.md). Calling routes without a server is covered in [testing](testing.md).

## Implementation and execution limits

`OrderedRouter` returns all matches in registration order without changing the priority of static routes. It scans the table sequentially rather than using a trie, so cost grows with the number of routes. Each handler gets its own parameter map. Regular expressions are prepared at registration; execution is bounded by a PCRE match limit of 10,000 and depth limit of 1,000. A PCRE error becomes an application error instead of being hidden as a 404.

For different limits, pass `new Hono(router: new \PhpHono\Router\OrderedRouter(matchLimit: 20_000, depthLimit: 2000))`. With a custom router, configure `strict` and `pathLimit` on the router itself. The table freezes after the first `match()`; calling `add()` afterwards throws `LogicException`.
