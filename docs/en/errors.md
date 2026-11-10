# Errors and 404 responses

**English** | [Русский](../ru/errors.md)

API 1.0 for PHP 8.5, namespace `PhpHono`. [Contents](README.md).

An expected application error can be expressed as an ordinary HTTP response. An unhandled `Throwable` is passed to `onError`. By default, the framework returns status 500 with `Internal Server Error` for an unknown error, without exposing a stack trace to the client.

## Expected rejections

Returning a Response stops the handler; an exception is not required:

```php
use PhpHono\Context;
use PhpHono\Hono;
use Psr\Http\Message\ResponseInterface;

$app = new Hono();
$app->get('/greeting', function (Context $c): ResponseInterface {
    $name = $c->req->query('name');

    if ($name === null || $name === '') {
        return $c->json(['error' => 'Provide a name'], 422);
    }

    return $c->json(['message' => 'Hello, ' . $name]);
});
```

The examples below use `$app` and the imports from this block. Choose status codes according to your API's contract: the framework does not automatically convert arbitrary validation failures to 422.

## Custom notFound

```php
$app->notFound(fn (Context $c): ResponseInterface =>
    $c->json(['error' => 'Route not found'], 404)
);
```

This callback runs when no route matches or the chain reaches its end without a response. A handler can also call it explicitly:

```php
$app->get('/removed-page', fn (Context $c): ResponseInterface => $c->notFound());
```

An HTTP method miss also returns 404 by default. Automatic 405 handling is not part of the core; opt in with `new \PhpHono\Middleware\MethodNotAllowed($app)`. OPTIONS is handled by an explicitly registered handler or by CORS middleware.

After `route('/api', $child)`, the root application's notFound applies; the child's notFound is not imported.

## Custom onError

The callback receives a `Throwable` and Context, then returns a `ResponseInterface`:

```php
$app->onError(function (\Throwable $error, Context $c): ResponseInterface {
    error_log('Request failed: ' . get_class($error));
    return $c->json(['error' => 'Internal server error'], 500);
});
```

This minimal example deliberately converts every incoming exception to 500. A custom `onError` replaces the default policy. If the API must preserve parser/HttpException status codes, check `$error instanceof \PhpHono\HttpException` and use its `$status` and `$response`. Alternatively, keep the default handler for those statuses.

Do not automatically return `$error->getMessage()` or a trace to the client: they can contain internal details. Choose a safe public message and send diagnostics to the server log. This example logs only the exception class.

If the custom `onError` itself throws an exception, the framework applies the built-in 500 fallback once without calling the custom callback again. A child `onError` imported through `route()` takes priority for the corresponding routes.

## HttpException

`PhpHono\HttpException` holds an HTTP status, message, optional prepared Response and previous exception. The default handler uses that HTTP response; the exception's status overrides the status of the nested Response.

```php
throw new \PhpHono\HttpException(409, 'Conflict');
```

Signature: `__construct(int $status, string $message = '', ?ResponseInterface $response = null, ?Throwable $previous = null)`. Its public readonly properties are `$status` and `$response`; the message is available through `getMessage()`. The status must be 400–599. Only put text intended for the client in the message.

## Middleware errors

An exception from a downstream handler is handled before `$next()` returns. Upstream middleware can add headers to the error response after `$next()`:

```php
$app->use('*', function (Context $c, callable $next): void {
    $next();
    $c->header('X-App', 'php-hono');
});
```

Register this middleware before the endpoint. If it throws an exception itself after `$next()`, that error is also passed to onError. Calling `$next()` twice from the same middleware is a developer error.

Distinguish two cases: when the chain reaches its end without a response, notFound runs; when a handler stops the chain without a Response, `setResponse()` or `$next()`, that is an error and returns 500 by default. The same rule applies to a single handler.

## Common status codes

This table describes the default policy. A custom `onError` can change the response.

| Status | Cause |
| --- | --- |
| 400 | Invalid JSON or malformed percent encoding/UTF-8 in the URI |
| 404 | No matching route/method; explicit `$c->notFound()` |
| 413 | A body limit that can be checked was exceeded, or the transport reported a corresponding failure |
| 414 | The path length limit was exceeded; the default is 8192 bytes |
| 415 | Multipart input arrived without confirmed adapter parsing |
| 500 | An unhandled Throwable, middleware/finalization error or failure in a custom onError |

HEAD passes through the GET chain and error handling, then receives a response without a body. Its status and permitted headers are preserved. Do not register a separate HEAD handler expecting it to run: the selected Hono v4 contract does not support that behavior.

## After response emission has started

HTTP streaming/SSE is implemented. Once headers have been sent, the status and body cannot be replaced with a new error response. A producer failure is logged and the stream is closed. This differs from ordinary handler errors before response emission; details and support status are in [deployment](deployment.md).

Next: [Context and responses](context-response.md), [middleware](middleware.md), [testing](testing.md).
