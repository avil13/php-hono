# Built-in middleware and cookies

**English** | [Русский](../ru/builtin-middleware.md)

The implemented php-hono 1.0 API for PHP 8.5. All middleware are callable objects: register them with `$app->use($path, $middleware)` or before the endpoint in `$app->get/post(...)`. Registration order determines execution order. The examples in each section are separate configuration options: register middleware before the corresponding endpoints and the first dispatch. The shared pipeline and third-party PSR-15 middleware are covered in [middleware](middleware.md).

```php
use PhpHono\Context;
use PhpHono\Hono;
use PhpHono\Middleware\BodyLimit;
use PhpHono\Middleware\Cors;
use PhpHono\Middleware\RequestId;
use PhpHono\Middleware\SecureHeaders;
use Psr\Http\Message\ResponseInterface;

$app = new Hono();
$app->use('*', new RequestId(), new SecureHeaders());
$app->use('/api/*', new Cors(origin: 'https://client.example'));
$app->use('/api/*', new BodyLimit(maxBytes: 256 * 1024));
$app->post('/api/echo', fn(Context $c): ResponseInterface => $c->text($c->req->text()));
```

## Validator

```text
new PhpHono\Middleware\Validator(string $target, callable $validate)
```

Targets: `json`, `form`, `query`, `param`, `header`, `cookie`. The constructor rejects any other target with `InvalidArgumentException`. The callback receives `(mixed $input, Context $c)` and returns transformed data or a `ResponseInterface` for an early response. The returned value, including `null` and `false`, is stored in `$c->req->valid($target)`; it does not persist into the next request. No schema library is required.

```php
use PhpHono\Middleware\Validator;

$app->post('/api/greetings', new Validator('json', static function (mixed $input, Context $c): mixed {
    if (!$input instanceof stdClass || !property_exists($input, 'name') || !is_string($input->name)) {
        return $c->json(['error' => 'name must be a string'], 422);
    }
    return ['name' => trim($input->name)];
}), static fn(Context $c): ResponseInterface => $c->json($c->req->valid('json')));
```

| Target | Callback input |
| --- | --- |
| `json` | The result of `req->json()` for `application/json` and `application/*+json`; otherwise `[]` |
| `form` | URL-encoded fields with repeated values collected; for multipart, fields prepared by SAPI |
| `query` | A single value is a string, repeated values are a list of strings; names are preserved |
| `param` | Parameters of the currently matched route |
| `header` | A map of string values with lowercase names |
| `cookie` | The result of `Cookie::get($c)` |

Malformed JSON or form encoding remains a 400 error, and exceeding the limit produces 413; the callback is not called in either case. Calling `valid()` without a successfully stored target throws `LogicException`. For parameters, place the validator on the `/:id` route, rather than in a global `use('*', ...)`, which has no endpoint parameters. Implementation: [Validator.php](../../src/Middleware/Validator.php).

## BodyLimit

```text
new PhpHono\Middleware\BodyLimit(int $maxBytes, ?callable $onError = null)
```

The limit is nonnegative and measured in bytes; zero allows only an empty body. The middleware checks `Content-Length`, then reads the actual body with a bounded reader before `$next()`. A missing or falsely low length does not bypass the check. Reading stops at no more than limit + 1 bytes. With `Transfer-Encoding`, the declared length is not treated as a trusted size; the actual body is still checked.

The limit can only lower `Hono(maxBodySize: ...)`, which defaults to 8 MiB. Cached bytes are also checked if the limit is lowered further. The endpoint rereads the same cache without losing data. Register BodyLimit before parsers and the bridge if the route limit must apply before they buffer the body.

Exceeding the limit produces 413 by default. A callback `(Context $c): ResponseInterface` lets you customize the error body:

```php
$app->use('/upload', new BodyLimit(1_048_576,
    static fn(Context $c): ResponseInterface => $c->json(['error' => 'File too large'], 413),
));
```

An invalid `Content-Length` produces 400 without calling this callback. The callback applies only to the BodyLimit check, not endpoint errors. SAPI multipart data has already been parsed by PHP: `post_max_size`, `upload_max_filesize`, and HTTP server limits apply before the framework. Learn more: [reading requests](request.md). Implementation: [BodyLimit.php](../../src/Middleware/BodyLimit.php).

## Cors

```php
new PhpHono\Middleware\Cors(
    origin: '*',
    allowMethods: ['GET', 'HEAD', 'PUT', 'POST', 'DELETE', 'PATCH', 'QUERY'],
    allowHeaders: [],
    credentials: false,
    exposeHeaders: [],
    maxAge: null,
);
```

`origin` accepts an exact origin string, a list of allowed origins, or a callback `(string $origin, Context $c): ?string`. The callback returns the allowed origin or `null` to deny it. An empty string also means denial. A list containing `'*'` allows any origin. Without an incoming `Origin`, no allow headers are added.

`credentials: true` is incompatible with a wildcard: for a string or list configuration, the constructor immediately throws `InvalidArgumentException`; a `'*'` result from a callback is checked during the request. Use an exact allowlist for credentials. `maxAge` is a nonnegative number of seconds, or `null` to omit the header.

A preflight is an `OPTIONS` request with nonempty `Origin` and `Access-Control-Request-Method`. It ends with a 204 response before the endpoint, without a body, `Content-Type`, or `Content-Length`. An ordinary `OPTIONS` request continues through the pipeline. With `allowHeaders: []`, validated HTTP token names from `Access-Control-Request-Headers` are reflected, and `Vary` includes that header name. Invalid requested header names produce 400. Pass an `allowHeaders` list for a fixed set of allowed headers.

For a dynamic or exact origin, `Vary: Origin` is added; existing values are merged without duplicates, and `Vary: *` is preserved. Headers are applied after `$next()`, so they also apply to downstream 404/500 responses. The middleware replaces its own `Access-Control-*` headers: an endpoint cannot bypass the allowlist by returning `Access-Control-Allow-Origin: *`. `Set-Cookie` and other headers are preserved. Denying an origin does not itself replace the response with 403: the browser receives no permission to read it.

CORS does not replace authentication or CSRF checks. Implementation: [Cors.php](../../src/Middleware/Cors.php).

## SecureHeaders

```text
new PhpHono\Middleware\SecureHeaders(array $overrides = [], bool $removePoweredBy = true)
```

After the endpoint or a handled downstream error, the middleware sets the following values:

| Header | Value |
| --- | --- |
| `Cross-Origin-Resource-Policy` | `same-origin` |
| `Cross-Origin-Opener-Policy` | `same-origin` |
| `Origin-Agent-Cluster` | `?1` |
| `Referrer-Policy` | `no-referrer` |
| `Strict-Transport-Security` | `max-age=15552000; includeSubDomains` |
| `X-Content-Type-Options` | `nosniff` |
| `X-DNS-Prefetch-Control` | `off` |
| `X-Download-Options` | `noopen` |
| `X-Frame-Options` | `SAMEORIGIN` |
| `X-Permitted-Cross-Domain-Policies` | `none` |
| `X-XSS-Protection` | `0` |

`$overrides` keys are compared case-insensitively; a string sets the value, and `null` disables setting that header in the middleware. Disabling it does not remove a value set by the endpoint itself. `X-Powered-By` is removed by default. CSP and COEP are not added automatically; set CSP explicitly:

```php
$app->use('*', new SecureHeaders([
    'Content-Security-Policy' => "default-src 'self'; object-src 'none'",
    'X-Frame-Options' => null,
]));
```

Choose CORP/COOP/CSP and HSTS settings that fit your application's resources and HTTPS policy. Implementation: [SecureHeaders.php](../../src/Middleware/SecureHeaders.php).

## RequestId

```text
new PhpHono\Middleware\RequestId(string $headerName = 'X-Request-Id', string $contextKey = 'requestId')
```

Incoming values are accepted if they contain 1–255 characters from `A-Z a-z 0-9 _ = -`. Otherwise, a new random 128-bit identifier is generated: 32 hexadecimal characters from `random_bytes(16)`. This is not a UUID. The value is available through `$c->get('requestId')` and the response header; the middleware reapplies the header after `$next()`, including after a handled error.

Configure the header name and context key explicitly. A request ID helps correlate events, but does not establish a user's identity: the client can choose any valid incoming ID. Implementation: [RequestId.php](../../src/Middleware/RequestId.php).

## Logger

```text
new PhpHono\Middleware\Logger(callable $sink, ?callable $onFailure = null)
```

The required `$sink` receives an array `{method, path, status, durationMs, requestId}`. Time is measured with a monotonic clock. The entry contains no query string, body, cookies, or Authorization. Logging happens once when exiting the middleware, including for 404/500 responses. `requestId` is read from Context under the `requestId` key; if the value is not a string, it is `null`.

```php
use PhpHono\Middleware\Logger;

$app->use('*', new RequestId(), new Logger(
    static function (array $entry): void {
        error_log(json_encode($entry, JSON_THROW_ON_ERROR));
    },
    static function (Throwable $failure): void {
        error_log('Failed to write the access log');
    },
));
```

In this example, the application chooses the I/O. The framework does not write to stdout or select a hidden log sink. A sink exception does not change the HTTP response: it is passed to the optional `onFailure(Throwable): void`. Without a diagnostic callback, the write failure is discarded. An exception from `onFailure` itself does not replace the response either. In production, connect independent diagnostics or metrics for sink failures. Implementation: [Logger.php](../../src/Middleware/Logger.php).

## Cookie

`PhpHono\Helper\Cookie` provides static helpers:

```text
Cookie::get(Context $c, ?string $name = null): string|array|null;
Cookie::parse(string $header): array;
Cookie::set(Context $c, string $name, string $value, array $options = []): void;
Cookie::delete(Context $c, string $name, array $options = []): void;
Cookie::serialize(string $name, string $value, array $options = []): string;
```

`get($c)` returns a cookie map, while `get($c, 'name')` returns the value or `null`. The first cookie with the same name takes precedence. Percent encoding is decoded, while `+` remains `+`. An invalid percent sequence or invalid UTF-8 is preserved in its original encoded form; a malformed pair is skipped.

```php
use PhpHono\Helper\Cookie;

$app->post('/preferences', static function (Context $c): ResponseInterface {
    Cookie::set($c, 'theme', 'dark', [
        'path' => '/',
        'maxAge' => 3600,
        'secure' => true,
        'httpOnly' => true,
        'sameSite' => 'Lax',
    ]);
    return $c->text('Saved');
});
```

Options: `domain: string`, `path: string` (default `/`), `expires: DateTimeInterface`, `maxAge: int >= 0`, `secure: bool`, `httpOnly: bool`, and case-insensitive `sameSite: 'Strict'|'Lax'|'None'`. Unknown options, incorrect types, and attempts to inject attributes are rejected with `InvalidArgumentException`. The value is encoded with `rawurlencode()`, and Expires is formatted in GMT.

`SameSite=None` and the `__Secure-` prefix require `secure: true`. `__Host-` additionally requires `path: '/'` and no `domain`. Helpers add separate `Set-Cookie` headers both before and after `$next()`; values are not joined with commas.

To delete a cookie, pass the same path/domain used when setting it: `Cookie::delete($c, 'theme', ['path' => '/'])`. The helper sends an empty value, `Max-Age=0`, and the Unix epoch date. For a secure prefix, keep `secure: true`. Cookie signing and encryption are not provided. Implementation: [Cookie.php](../../src/Helper/Cookie.php).

Other features: [middleware pipeline](middleware.md), [request](request.md), [Context and responses](context-response.md), [Context extensions and DI](context-extensions.md).
