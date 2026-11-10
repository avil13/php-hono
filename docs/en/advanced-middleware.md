# HTTP middleware 1.0

**English** | [Русский](../ru/advanced-middleware.md)

[Contents](README.md) · [Middleware order](middleware.md) · [Core middleware](builtin-middleware.md)

All classes below belong to `PhpHono\Middleware` and are registered before endpoints with `$app->use($path, $middleware)`. They can also appear in the handler list of an individual route. The examples are independent; `$app` is an instance of `PhpHono\Hono`.

## Basic and Bearer authentication

```php
use PhpHono\Middleware\BasicAuth;
use PhpHono\Middleware\BearerAuth;

$app->use('/admin/*', BasicAuth::credentials($username, $password, realm: 'Administration'));
$app->use('/api/*', BearerAuth::token($apiToken));
```

Secrets come from application configuration. Basic sends credentials encoded as base64, while Bearer sends the token itself: use HTTPS. The helpers compare secrets with `hash_equals()`. User storage, password hashing, JWT, and token issuance are outside the middleware's scope.

Use the constructors to verify credentials through your own service:

```php
use PhpHono\Context;
use PhpHono\Middleware\BasicAuth;
use PhpHono\Middleware\BearerAuth;

$app->use('/admin/*', new BasicAuth(
    verify: fn (string $username, string $password, Context $c): bool =>
        $accounts->verifyPassword($username, $password),
    realm: 'Administration',
));

$app->use('/api/*', new BearerAuth(
    verify: fn (string $token, Context $c): bool => $tokens->isValid($token),
));
```

The default `realm` is `Restricted`. Missing credentials, malformed input, or a verifier rejection produce 401 with `WWW-Authenticate`; the endpoint does not run. Basic accepts one Authorization header, valid base64, and the first `:` as the username/password separator. Bearer accepts one header and a token containing letters, digits, or `-._~+/`, optionally followed by `=` characters. Authorization schemes are compared case-insensitively. Exceptions from the verifier go through the application's `onError`; credentials are not included in the default error response.

## CSRF

```php
use PhpHono\Middleware\Csrf;

$app->use('/forms/*', new Csrf());
```

By default, the middleware accepts an Origin with the same scheme/host/port as the request's PSR-7 URI, **or** `Sec-Fetch-Site: same-origin`. Standard HTTP/HTTPS ports are normalized. `X-Forwarded-Host` and `X-Forwarded-Proto` do not change the trusted origin. Behind a reverse proxy, construct the correct URI in a trusted external adapter.

The check applies to methods other than GET/HEAD/OPTIONS and browser form content types: `application/x-www-form-urlencoded`, `multipart/form-data`, `text/plain`, and `text/ping`. A missing Content-Type is treated as `text/plain`. JSON is outside this profile. Rejected requests receive 403 before the endpoint runs.

```php
$app->use('/forms/*', new Csrf(
    origins: ['https://app.example.com'],
    fetchSites: [],
));
```

An empty `origins` list means the same origin; a nonempty list specifies allowed HTTP(S) origins. The default `fetchSites` is `['same-origin']`; `same-site`, `none`, and `cross-site` are also accepted configuration values. A successful check of **either** source is sufficient, as in the selected Hono profile. Therefore, `fetchSites: []` in this example requires an Origin match. Adding `cross-site` substantially broadens the policy. CORS does not replace CSRF protection, and this middleware does not replace a separate CSRF token policy when the application needs one.

## ETag and gzip

```php
use PhpHono\Middleware\ETag;
use PhpHono\Middleware\Compress;

$app->use('/documents/*', new ETag());
$app->use('/documents/*', new Compress(minimumLength: 1024, level: -1));
```

Order matters: after the endpoint, Compress runs first, followed by the outer ETag middleware. The strong ETag therefore describes the actual selected gzip or identity bytes. An integration test covers this combination. With the reverse order, Compress converts an existing strong tag to a weak tag (`W/`), preserving semantic equivalence.

`ETag(weak: false)` uses SHA-256 for a finite, readable, seekable body of a successful GET response. It hashes the entire body in chunks and restores the original position. An existing ETag is preserved. SHA-256 is a PHP API choice; matching JavaScript Hono's digest bytes is not promised.

The middleware supports `If-None-Match: *`, tag lists, and weak comparison for GET/HEAD, including commas inside a quoted tag. A match produces 304 without a body or Content-Type/Content-Length/Content-Encoding/Transfer-Encoding; other headers are preserved. It skips 204/205/206, non-2xx responses, streaming bodies, and nonseekable bodies. HEAD does not read the body to compute a new hash: a conditional response can use an ETag supplied in advance.

`Compress` supports gzip when ext-zlib is available. The default `minimumLength` is 1024 bytes and `level` is the zlib default `-1` (allowed values: `-1..9`). Quality values `q` and wildcards are respected; explicit `gzip;q=0` takes precedence over `*`. An explicit preference for identity is preserved. Unsupported encodings or unavailable ext-zlib fall back to identity when the client permits it. An explicit identity ban with no applicable gzip produces an empty 406. When identity is forbidden, even a small body may be compressed.

The middleware extends `Vary: Accept-Encoding`, preserves `Vary: *`, and sets Content-Encoding and the compressed body's length. It does not transform already encoded responses, bodyless statuses, 206, `Cache-Control: no-transform`, streaming bodies, or nonseekable bodies. When identity is explicitly forbidden, an inability to transform produces 406, except for already encoded and bodyless responses. An ordinary HEAD does not run compression; if identity is forbidden and gzip is applicable, gzip metadata is set without reading the body or calculating its length. A 304 does not trigger body processing.

The output buffer uses `php://temp` with a 2 MiB threshold, after which data moves to a temporary file. This produces a finite response; it is not streaming delivery to the client. Neither middleware knows which application data is sensitive: choose the compression policy deliberately for responses that mix secrets with reflected user input.

## Static files

```php
use PhpHono\Middleware\StaticFiles;

$app->use('/assets/*', new StaticFiles(__DIR__ . '/public/assets', prefix: '/assets'));
```

The root must exist and be a directory; ext-fileinfo is required. The default prefix is `/`. GET opens the file as a PSR-7 stream; HEAD returns the same Content-Type/Content-Length/Last-Modified without opening the body. Missing files and unsupported methods delegate downstream. Directory listing, index resolution, and Range are not implemented; a Range header does not automatically enable a partial response.

The root is enforced using `realpath` and a directory-boundary check. Traversal segments, encoded separators, and backslashes are rejected; the HTTP input layer also rejects NUL. Symlinks are allowed only within the root. After opening a file, its inode/device and path are checked; if response stream creation fails, the resource is closed. A successful response transfers resource ownership to the PSR stream, which must be closed after consumption; the default emitter does this.

The root must contain only public files and must not be writable by untrusted users. These checks do not replace filesystem permissions or an atomic filesystem sandbox against concurrent hostile file replacement. Use a web server/CDN for high-volume file delivery.

`Compress` and `StaticFiles` accept an optional `streams: StreamFactoryInterface`; the default is Nyholm PSR-7. This lets body creation use the application's chosen PSR-17 implementation.

## 405 and trailing slashes

```php
use PhpHono\Middleware\MethodNotAllowed;
use PhpHono\Middleware\TrailingSlash;

$app->use('*', new MethodNotAllowed($app));
$app->get('/items', fn (\PhpHono\Context $c) => $c->json([]));
```

After an ordinary 404, the middleware looks for endpoints at the same path with other methods. On a method mismatch, it returns 405 and `Allow`, adding HEAD when GET is present. `use()` does not define allowed endpoint methods; `all()` means no restriction. An explicit `on('HEAD', ...)` is ignored because Hono selects the GET chain for HEAD. An intentional 404 from a handler for the same method and errors handled through `onError` are not replaced. Without this middleware, a method miss remains 404.

```php
$app->use('*', new TrailingSlash()); // Trim: /about/ → /about.
// Alternative for a new application:
// $app->use('*', new TrailingSlash(append: true, alwaysRedirect: true));
```

Defaults: `append: false`, `alwaysRedirect: false`. By default, a redirect happens only after an ordinary 404; an existing route is preserved. `alwaysRedirect: true` applies the policy before downstream execution. GET/HEAD use 301 and preserve the query string; POST and other methods are not redirected. The root `/` is unchanged. This feature does not change the router's default `strict: true` setting.
