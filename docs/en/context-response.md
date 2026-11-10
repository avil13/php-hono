# Context and responses

**English** | [Русский](../ru/context-response.md)

php-hono 1.0 API for PHP 8.5. [Contents](overview.md).

A new `Context` is created for each request. It brings together `$c->req`, request variables, response state and convenient `ResponseInterface` factories. Handlers return responses; an [adapter](deployment.md) sends them to the client.

## Creating a response

```php
use PhpHono\Context;
use PhpHono\Hono;
use Psr\Http\Message\ResponseInterface;

$app = new Hono();
$app->get('/', fn (Context $c): ResponseInterface => $c->text('Hello!'));
$app->get('/health', fn (Context $c): ResponseInterface => $c->json(['ok' => true]));
$app->get('/home', fn (Context $c): ResponseInterface => $c->redirect('/'));
```

| Method | Purpose | Default Content-Type |
| --- | --- | --- |
| `text($value, $status = null, $headers = [])` | A text response | `text/plain; charset=UTF-8` |
| `json($value, $status = null, $headers = [])` | Encode a PHP value as JSON | `application/json` |
| `html($value, $status = null, $headers = [])` | A prepared HTML string | `text/html; charset=UTF-8` |
| `body($value, $status = null, $headers = [])` | A body with explicitly chosen headers | Set by the application |
| `redirect($url, $status = 302)` | A redirect | Not an HTML renderer |
| `notFound()` | A response from the root notFound handler | Depends on the handler |

The examples below use `$app` and the imports from the first block. Specify the format explicitly for a custom body:

```php
$app->get('/export', fn (Context $c): ResponseInterface =>
    $c->body("name\nphp-hono\n", 200, ['Content-Type' => 'text/csv; charset=UTF-8'])
);
```

Calling a factory does not finalize Context. Return the resulting Response or set it through `setResponse()`. If a handler does not return a response, set one or call `$next()`, the framework treats this as a developer error.

## Status

Pass the status as the factory's second argument, or set a default for subsequent factory calls through `status()`:

```php
$app->post('/accepted', function (Context $c): ResponseInterface {
    $c->status(202);
    return $c->json(['accepted' => true]);
});
```

`status()` and the factories accept 100–599; `redirect()` accepts only 300–399. An invalid status throws `InvalidArgumentException`. An explicit factory status takes precedence. If no status is passed and `status()` has not been called, the default is 200. `status()` does not change an existing Response. After `$next()`, replace the object through `withStatus()` and `setResponse()`.

For informational 1xx statuses and for 204, 205 and 304, the body is removed regardless of what the handler supplied. HEAD also returns no body but runs the GET chain; see [routing](routing.md).

## Headers

```php
$app->get('/report', function (Context $c): ResponseInterface {
    $c->header('Cache-Control', 'no-store');
    $c->header('X-Tag', 'summary');
    $c->header('X-Tag', 'daily', true);
    $c->header('X-Unused', null);

    return $c->json(['report' => 'daily']);
});
```

Header names are validated as HTTP tokens; CR/LF and control characters in values are rejected with `InvalidArgumentException`. `header($name, $value)` replaces the value; a third argument of `true` appends a value, and `null` removes the header. Names are case-insensitive. After `$next()`, the same method changes the current response.

Until the first Response is set, headers are collected in Context. They are applied once when the Response is set: ordinary headers explicitly provided by the Response itself take precedence. `Set-Cookie` values are combined as separate lines in the order “prepared in Context → provided by the Response”. The prepared header queue is then cleared, so a removed value does not reappear.

Multiple cookies can be supplied as separate values:

```php
$app->get('/preferences', function (Context $c): ResponseInterface {
    $c->header('Set-Cookie', [
        'theme=dark; Path=/; Secure; HttpOnly; SameSite=Lax',
        'language=en; Path=/; Secure; HttpOnly; SameSite=Lax',
    ]);

    return $c->text('Preferences saved');
});
```

This example requires HTTPS because it uses `Secure`. For dynamic values, use [Cookie::set/delete](builtin-middleware.md#cookie). Do not join `Set-Cookie` values with commas or insert unchecked user input into a prepared cookie string.

## The current response and explicit replacement

`response()` returns the current Response, or `null` if none has been set. `setResponse()` explicitly sets or replaces it. For example, middleware can change the status after the endpoint runs:

```php
$app->use('/async-report', function (Context $c, callable $next): void {
    $next();
    $response = $c->response();

    if ($response !== null && $response->getStatusCode() === 200) {
        $c->setResponse($response->withStatus(202));
    }
});
$app->get('/async-report', fn (Context $c): ResponseInterface =>
    $c->json(['queued' => true])
);
```

A plain `return $otherResponse` after `$next()` does not replace an already finalized response. Use `setResponse()` for replacement and `header()` for header changes.

The replacement rules are:

1. Explicit headers in the new response take precedence.
2. Missing headers are inherited from the previous response, except `Content-Type`, `Content-Length`, `Content-Encoding`, `Transfer-Encoding`, `ETag` and `Last-Modified`: these belong to the replaced body.
3. An explicit new `Set-Cookie` list replaces the entire old list. If no new list is supplied, the old list is inherited. Setting the same response again does not duplicate cookies.

To remove a header, use `$c->header('X-Name', null)`. Calling `setResponse($response->withoutHeader('X-Name'))` may restore its value from the previous response under the inheritance rule. The PSR-15 bridge uses `setResponseExact()` and preserves header removals made by PSR middleware without inheriting old headers. That method is for adapters; use `setResponse()` for ordinary response replacement.

## HTML and JSON

`html()` accepts a prepared string and does not escape values automatically. Escape text inserted into HTML:

```php
$app->get('/hello', function (Context $c): ResponseInterface {
    $name = $c->req->query('name') ?? 'guest';
    $escaped = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return $c->html('<h1>Hello, ' . $escaped . '!</h1>');
});
```

This example handles HTML text content. Embedding values in JavaScript, CSS or URLs requires the rules for that context. JSX is outside the project scope.

`json()` encodes the supplied PHP value. For example, `['ok' => true]` becomes a JSON object, and `['php', 'http']` becomes a JSON array. An encoding failure does not silently become an empty response: it goes through [error handling](errors.md).

## Request variables

Use `set/get` to pass data from middleware to an endpoint:

```php
$app->use('/catalog/*', function (Context $c, callable $next): void {
    $c->set('locale', $c->req->query('locale') === 'en' ? 'en' : 'ru');
    $next();
});
$app->get('/catalog/items', fn (Context $c): ResponseInterface =>
    $c->json(['locale' => $c->get('locale')])
);
```

`get()` returns `null` both for an absent key and for an explicitly stored null. Use `has()` to distinguish them. Context values do not carry over to the next request; do not retain Context itself in static/global state or a long-lived service.

Application objects can be retrieved with `extension(Type::class): object`, annotated with generic PHPDoc. First store the object through `set(Type::class, $object)`. The method reads that exact variable key and checks `instanceof Type`; a missing value, null or a wrong type causes `LogicException`, handled by the existing onError during dispatch. No object is created or separately cached. The complete contract, interface keys and DI examples are covered in [Context extensions](context-extensions.md).

Bindings are available through the readonly array `$c->env`. `new Hono(env: ['mode' => 'production'])` sets values for `handle($request)`. Calls to `fetch($request, $env)` and `request($input, $options, $env)` use the explicitly supplied array; when omitted, it is `[]`, without automatically merging the constructor configuration. Objects inside the array do not become immutable automatically. `$c->error` contains the exception passed to the error handler; after the error is handled, it remains available to upstream middleware.

Next: [middleware](middleware.md), [errors](errors.md), [compatibility](compatibility.md).
