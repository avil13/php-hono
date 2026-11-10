# Public API 1.0

**English** | [Русский](../ru/api.md)

[Contents](README.md). This is the implemented API for the selected PHP profile. The namespace is `PhpHono`; `Internal` classes and methods marked `@internal` are not part of the stable extension API.

## Hono

```php
new Hono(
    strict: true,
    env: [],
    maxBodySize: 8_388_608,
    maxPathLength: 8192,
    responseFactory: null,
    streamFactory: null,
    router: null,
);
```

The factories accept PSR-17 interfaces; the router accepts `Router\RouterInterface`. Nyholm and `OrderedRouter` are the defaults. The body limit applies to reader/bridge buffering; transport limits are configured separately. `handle()` uses the constructor's `env`; `fetch()` and `request()` use their supplied array, which defaults to empty.

| Method | Purpose |
| --- | --- |
| `get/post/put/patch/delete/options/query(path, ...handlers): static` | Register a method; `query` means HTTP QUERY |
| `on(methods, paths, ...handlers): static` | A string or list of methods/paths |
| `all(path, ...handlers): static` | An endpoint for all methods |
| `use(path, ...middleware): static` | Middleware with an explicit path, usually `*` |
| `basePath(prefix): Hono` | A prefixed view sharing the route table |
| `route(prefix, child): static` | A snapshot of the child's registered routes |
| `onError(callable(Throwable, Context): ResponseInterface): static` | An error handler |
| `notFound(callable(Context): ResponseInterface): static` | A 404 handler |
| `handle(ServerRequestInterface): ResponseInterface` | The PSR-15 entry point |
| `fetch(ServerRequestInterface, env = []): ResponseInterface` | Dispatch with an explicit env |
| `request(string\|ServerRequestInterface, options = [], env = []): ResponseInterface` | An in-memory request; options: method, headers, body |
| `allowedMethods(path): list<string>` | Endpoint methods for opt-in 405; an empty list also represents unrestricted ALL |

Registration ends at the first dispatch. All matching handlers run in registration order until an early response. `$next(): ResponseInterface` may be called once. After `$next()`, change the response through Context; a plain `return $newResponse` does not replace an already finalized response.

## Request and Context

`$c->req` is a `HonoRequest`, `$c->env` is a readonly reference to the request environment array, and `$c->error` is the handled Throwable or null.

| Request | Result |
| --- | --- |
| `raw()` | The current `ServerRequestInterface` |
| `method()/path()/url()` | Strings; path preserves percent encoding |
| `param(name = null)` | One decoded value or the current handler's parameter map |
| `query(name = null)` | The first value or a map of first values |
| `queries(name = null)` | A value list or a map of lists |
| `header(name = null)` | A string/null or a header map |
| `text()/json()` | Repeatable reads through the shared bounded cache |
| `parseBody(all = false)` | Form data; raw multipart is not parsed |
| `uploadedFiles()` | A tree of PSR uploaded files |
| `valid(target)` | The result of a completed Validator, otherwise LogicException |

| Context | Purpose |
| --- | --- |
| `set(key, value)/get(key)/has(key)` | A request-local store; has distinguishes absent keys from null |
| `extension(class-string<T>): T` | Check a registered object with instanceof, without creating one |
| `status(code): void` | The default status for subsequent helpers |
| `header(name, string\|list<string>\|null, append = false): void` | Set, append or remove a header |
| `body(string\|StreamInterface = '', status = null, headers = [])` | Create a Response |
| `text/html/json(value, status = null, headers = [])` | Create a Response with the corresponding Content-Type |
| `redirect(url, status = 302)` | An empty Response with Location |
| `notFound()` | Create a response through the registered notFound handler |
| `response(): ?ResponseInterface` | The current finalized response |
| `setResponse(ResponseInterface): void` | Explicit replacement using the documented header inheritance policy |

Response helpers return `ResponseInterface` and do not finalize Context themselves. Statuses range from 100 to 599; `HttpException` accepts only 400–599. See [Request](request.md), [Context](context-response.md) and [errors](errors.md).

## Middleware and helpers

Native middleware are invokable objects `(Context, callable $next)`: they return ResponseInterface/null or use void, depending on early responses and post-processing. `$next(): ResponseInterface` returns the downstream response. Constructors and options are listed in [built-in](builtin-middleware.md) and [additional middleware](advanced-middleware.md). `Middleware\Psr15::wrap(MiddlewareInterface): Closure` adapts an existing PSR-15 object.

`Helper\Html::escape(string): string` escapes HTML text/attribute values. `Helper\Cookie::{get,set,delete}` are described under [Cookie](builtin-middleware.md#cookie).

`Helper\Streaming::{stream,streamText,streamSSE}(Context, callable $producer, ?int $status = null, array $headers = []): ResponseInterface`. Without an explicit status, the Context default is used. The first two producers return `iterable<string>`. An SSE producer returns `iterable<array{data:string,event?:string,id?:string,retry?:int}>`. The producer starts when the body is read; HEAD does not start it. `Http\StreamingBody` is a single-use, non-seekable PSR stream.

## Runtime

`Adapter\Sapi::request(): ServerRequestInterface` creates a request from globals. `Sapi::run(RequestHandlerInterface, ?SapiEmitter = null): void` receives the request and sends the response. `SapiEmitter(?callable $onError = null)` provides `emit(ResponseInterface, bool $head = false): void` and closes the body after emission.

`Adapter\FrankenPhpWorker::run(RequestHandlerInterface, int $maxRequests = 500, ?callable $cleanup = null): void` works only in FrankenPHP worker mode. Cleanup is called after every request; application objects are created before the loop. Resource ownership and configuration are covered in [deployment](deployment.md).
