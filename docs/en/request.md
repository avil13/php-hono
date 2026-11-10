# Request

**English** | [Русский](../ru/request.md)

php-hono 1.0 API for PHP 8.5. [Contents](overview.md).

Inside a handler, `$c->req` provides access to the request. Reading methods do not send a response: pass their result to one of the [response helpers](context-response.md).

## Path, parameters and headers

```php
use PhpHono\Context;
use PhpHono\Hono;
use Psr\Http\Message\ResponseInterface;

$app = new Hono();
$app->get('/users/:id', function (Context $c): ResponseInterface {
    return $c->json([
        'id' => $c->req->param('id'),
        'method' => $c->req->method(),
        'path' => $c->req->path(),
        'accept' => $c->req->header('Accept'),
    ]);
});
```

| Method | Result |
| --- | --- |
| `method()` | The original HTTP method, such as `GET` or `HEAD` |
| `path()` | The encoded URI path; an empty path is returned as `/` |
| `url()` | The request URL |
| `param('id')` | A parameter of the current matched route, or `null` |
| `param()` | The current route's parameter map |
| `header('Accept')` | The header value or `null`; names are case-insensitive |
| `header()` | A header map with lowercase names |
| `raw()` | The current `ServerRequestInterface`, including changes forwarded by PSR-15 middleware |

Parameters belong to the current handler. Global `'*'` middleware does not receive endpoint parameters in advance. Parameter values are decoded after route matching; do not call `urldecode()` on them again. `%2F` inside a value does not become a segment separator before matching. Malformed percent encoding or UTF-8 in the URI results in 400.

`path()` preserves the original encoded URI path, including a trailing `/` with `strict: false`. For example, `/about/` may match the route `/about`, but `req->path()` returns `/about/`. This is an explicit difference from Hono, which normalizes the request path for non-strict routing. The original path is needed by trailing slash redirect and static file middleware; `param()` still returns parameters from the route that actually matched.

To access PSR-7 attributes, use `$c->req->raw()->getAttribute('name')`. PSR-7 messages are immutable: `withHeader()` returns a new request and does not itself replace the request in Context. Existing PSR-15 middleware is connected through an adapter; the [bridge](middleware.md#psr-15-middleware) forwards the modified request downstream and keeps the body available through the shared bounded cache.

## Query string

`query()` returns the first value of each parameter; `queries()` preserves all values. For example, given `/search?tag=php&tag=http&q=&user.name=Ann`:

| Call | Result |
| --- | --- |
| `$c->req->query('tag')` | `'php'` |
| `$c->req->queries('tag')` | `['php', 'http']` |
| `$c->req->query('q')` | `''` |
| `$c->req->query('missing')` | `null` |
| `$c->req->query('user.name')` | `'Ann'` |
| `$c->req->query()` | A map of first values |
| `$c->req->queries()` | A map of value lists |

An empty value is distinct from an absent parameter. `+` is decoded as a space. Names containing dots, spaces and `[]` are preserved: `tag[]` is a separate parameter name, not a reference to `tag`. This differs from PHP's `parse_str()` and the usual handling of `$_GET`. An empty parameter name is valid. PHP converts integer-like string array keys (`"0"`, `"42"`) to `int`; string values are not converted.

Example route, using `$app` and the imports from the first example:

```php
$app->get('/search', function (Context $c): ResponseInterface {
    return $c->json([
        'query' => $c->req->query('q') ?? '',
        'tags' => $c->req->queries('tag') ?? [],
    ]);
});
```

## JSON

`json()` returns a PHP value: a JSON object becomes `stdClass`, and a JSON array becomes a PHP array. Syntactically invalid JSON results in 400. Validating the shape of the data remains the responsibility of the application or validator middleware.

```php
$app->post('/greetings', function (Context $c): ResponseInterface {
    $data = $c->req->json();

    if (!$data instanceof \stdClass || !isset($data->name) || !is_string($data->name)) {
        return $c->json(['error' => 'Expected an object with a string name field'], 422);
    }

    return $c->json(['message' => 'Hello, ' . $data->name], 201);
});
```

The application chooses 422 in this example. `req->json()` itself parses the body regardless of `Content-Type`; the built-in JSON Validator checks the media type and accepts `application/json` or `application/*+json`. An empty body is a JSON error, resulting in 400, while JSON `null` returns PHP `null`. Integers outside PHP's integer range are preserved as strings by the parser to avoid losing precision.

`req->valid($target)` is intended for data already checked by validator middleware. Calling it without the corresponding validation is a developer error. The built-in [Validator](builtin-middleware.md#validator) stores any callback result, including `null`; reading a missing target throws `LogicException`.

## Text and repeated reads

`text()` returns the body as a string. `text()`, `json()` and `parseBody()` share a cache: reading the body in middleware must not deprive the endpoint of its data. Even a non-seekable stream is read only once through a bounded reader.

```php
$app->post('/echo', function (Context $c): ResponseInterface {
    return $c->text($c->req->text());
});
```

Reading `$c->req->raw()->getBody()` directly bypasses this contract. Use the wrapper methods for repeated reads. The default shared buffering limit is **8 MiB (8,388,608 bytes)**, configured with `new Hono(maxBodySize: 8_388_608)`. Exceeding it results in 413; the reader stops after at most limit + 1 bytes. Read failures and limit errors are retained: a repeated call cannot bypass the limit. [BodyLimit](builtin-middleware.md#bodylimit) lowers the limit for a route and checks the body before the endpoint, even if the endpoint does not read it. The global limit itself applies when the body is read or the PSR-15 bridge is entered; it does not replace transport/SAPI limits.

## URL-encoded forms

For `application/x-www-form-urlencoded`, use `parseBody()`:

```php
$app->post('/preferences', function (Context $c): ResponseInterface {
    $form = $c->req->parseBody();
    return $c->json($form);
});
```

Duplicate handling differs from query strings:

| Body | `parseBody()` | `parseBody(true)` |
| --- | --- | --- |
| `tag=php&tag=http` | `['tag' => 'http']` | `['tag' => ['php', 'http']]` |
| `tag=php` | `['tag' => 'php']` | `['tag' => 'php']` |
| `tag%5B%5D=php` | `['tag[]' => ['php']]` | `['tag[]' => ['php']]` |

By default, the last duplicate value wins. Passing `true` collects duplicates but does not turn a single ordinary field into an array. A field named `tag[]` always returns an array under that exact name. Expanding dotted names into nested objects (`dot:true`) is not supported. For other media types, `parseBody()` returns `[]`; it does not try to guess the format.

## Multipart and file uploads

Multipart is parsed by the SAPI/adapter before the application is called. `parseBody()` uses the prepared PSR request fields, and `uploadedFiles()` returns uploaded files as `UploadedFileInterface` objects or nested arrays of those objects.

```php
use Psr\Http\Message\UploadedFileInterface;

$app->post('/upload-info', function (Context $c): ResponseInterface {
    $form = $c->req->parseBody();
    $files = $c->req->uploadedFiles();
    $file = $files['attachment'] ?? null;

    if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
        return $c->json(['error' => 'A successfully uploaded attachment is required'], 422);
    }

    return $c->json([
        'description' => $form['description'] ?? '',
        'size' => $file->getSize(),
    ]);
});
```

Do not use a filename supplied by the client as a storage path. This example only reads metadata and does not save anything.

Limitations of this path:

- PHP may already have changed field names and discarded duplicates; full parity with Hono's raw multipart handling is not provided.
- `parseBody(true)` throws `InvalidArgumentException` for multipart.
- The adapter marks successfully prepared multipart, including an empty form, with the attribute `php_hono.multipart_parsed=true`. Without it, `parseBody()` returns a 415 error.
- When creating a PSR request in tests, prepare the parsed body/uploaded files first, then set the marker. The marker is not a parser.
- `post_max_size`, upload limits and web server limits apply before the framework runs. If the SAPI leaves no body, size or error indicator, the application cannot always distinguish lost data from an empty form.

## Request changes in the PSR-15 bridge

The bridge provides a read-only replay stream from the shared cache: it can be read and rewound, but `write()` is prohibited. To change the body, middleware passes a new request using `withBody()`. A new stream gets its own cache with the same lowered limit; cached JSON/form values and their validation results are cleared. Changes to headers, Cookie, Content-Type or the parsed body clear the corresponding validation results. When the bridge returns, the previous request view is restored. The internal methods `bodyStore()`, `withRaw()`, `setParams()` and `setValidated()` are for framework adapters; applications should use the read helpers, Validator and PSR-15.

Next: [Context and responses](context-response.md), [error handling](errors.md), [testing](testing.md).
