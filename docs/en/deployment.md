# Running the application

**English** | [Русский](../ru/deployment.md)

[Contents](README.md) · PHP 8.5+

`Hono::request()`, `handle()`, and `fetch()` return a PSR-7 Response. Use the SAPI adapter to send it to a client. `fetch()` synchronously handles an incoming request; it is not an HTTP client.

## PHP-FPM and the local server

Route registration returns the application from `app.php`; the front controller loads the autoloader and passes the application to the adapter:

```php
<?php

declare(strict_types=1);

use PhpHono\Adapter\Sapi;

require dirname(__DIR__) . '/vendor/autoload.php';

Sapi::run(require dirname(__DIR__) . '/app.php');
```

A complete example is available in [examples/public/index.php](../../examples/public/index.php). From the repository root:

```sh
php -S 127.0.0.1:8080 -t examples/public examples/public/index.php
```

In production, the document root must point to `public/`, with application routes directed to `index.php`. Source code, `vendor/`, configuration, and test fixtures must not be served as static files. PHP's built-in server is suitable for development; the production profile uses FPM or a FrankenPHP worker.

`Sapi::request()` creates a Nyholm ServerRequest and preserves the raw request target, cookies, parsed fields, and uploaded files. Path encoding is checked before URI normalization. Exceeding PHP's `post_max_size` or upload size limits produces 413; a partial upload produces 400, and server-side upload write errors produce 500. POST multipart with PHP parsing enabled receives the `php_hono.multipart_parsed` attribute. MIME syntax is not parsed manually; PHP and the server may read the body before the application starts.

Align `post_max_size`, `upload_max_filesize`, server limits, and the `BodyLimit` middleware. The adapter does not trust `X-Forwarded-Proto` when selecting the scheme: forwarded headers remain available to the application, but proxy trust must be configured explicitly outside the core. The client address comes from SAPI's `REMOTE_ADDR`.

## Your own PSR-15 pipeline

An external handler may rewrite the URI/method before entering Hono. Pass the final `RequestHandlerInterface` to `Sapi::run($pipeline)`. To manage the boundary yourself:

```php
use PhpHono\Adapter\Sapi;
use PhpHono\Adapter\SapiEmitter;

$request = Sapi::request();
$response = $pipeline->handle($request);
(new SapiEmitter())->emit($response, head: strtoupper($request->getMethod()) === 'HEAD');
```

The emitter preserves separate `Set-Cookie` values, suppresses the body for HEAD and bodyless statuses, reads the body in chunks, and closes it in `finally`. Do not replace it with `echo (string) $response->getBody()`: that loses HTTP metadata and buffers the stream. `Sapi::run()` additionally converts request-preparation errors into a safe HTTP response.

## Streaming and SSE

A producer returns `iterable<string>` and starts on the first body read. Reading status/headers/metadata does not invoke the producer. HEAD never starts the producer.

```php
use PhpHono\Context;
use PhpHono\Helper\Streaming;

$app->get('/stream', static function (Context $context) {
    return Streaming::streamText($context, static function (): Generator {
        try {
            yield "first\n";
            yield "second\n";
        } finally {
            // Release the producer's resources here.
        }
    });
});

$app->get('/events', static function (Context $context) {
    return Streaming::streamSSE($context, static function (): Generator {
        yield ['event' => 'update', 'id' => '42', 'retry' => 1000, 'data' => "line one\nline two"];
    });
});
```

`Streaming::stream()` does not set Content-Type; `streamText()` sets `text/plain; charset=UTF-8`; `streamSSE()` sets `text/event-stream; charset=UTF-8`, `Cache-Control: no-cache`, and `X-Accel-Buffering: no`. Optional `status` and `headers` arguments let you customize the response; omitting status preserves `Context::status()`. SSE `data` is a string, so the application must encode JSON itself. `event`/`id` reject line breaks and NUL; `retry` is a nonnegative number of milliseconds.

`StreamingBody` is a single-use, read-only, nonseekable PSR-7 stream of unknown size. An explicit `getContents()` or string cast reads the entire remaining stream. `ETag` and `Compress` skip StreamingBody. Third-party middleware that reads the whole body may remove the benefits of streaming.

The emitter flushes after each available chunk and stops reading when it detects a disconnected client. Deferred producers are released through `close()`; a generator's `finally` runs when an already-started stream is cancelled. Create the generator inside the producer and do not keep external references to it. Disconnect detection depends on the SAPI/proxy and usually happens after another write. A blocking producer cannot be forcibly interrupted between yields.

Once headers have been sent, a producer error can no longer change the status: the emitter closes the stream and logs the error class. Supply a custom diagnostic handler through `new SapiEmitter(onError: $callback)`. It receives the Throwable and does not send a second HTTP response. Output buffering, reverse proxy, and compression settings may delay delivery; test your actual infrastructure.

## FrankenPHP worker

[examples/worker.php](../../examples/worker.php) loads the application once:

```php
use PhpHono\Adapter\FrankenPhpWorker;

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/app.php';
FrankenPhpWorker::run($app, maxRequests: 500);
```

`FrankenPhpWorker::run(RequestHandlerInterface $app, int $maxRequests = 500, ?callable $cleanup = null)` uses the real `frankenphp_handle_request`. Calling it in ordinary PHP CLI fails with a clear error. `maxRequests` must be positive: once the limit is reached, the adapter returns and FrankenPHP restarts the worker. `cleanup` runs after every handled request, including failures. The cycle collector runs after cleanup inside the callback, before control returns to the native runtime, including when cleanup throws.

A minimal Caddyfile for the example, run from the repository root:

```caddyfile
{
    frankenphp
}
:8080 {
    root * examples/public
    php_server {
        worker {
            file examples/worker.php
            num 1
            match *
        }
    }
}
```

Start it with `frankenphp run --config Caddyfile`. For production, configure the site address, worker count, limits, and restarts through a process manager. The API and configuration were checked against the [FrankenPHP worker documentation](https://frankenphp.dev/docs/worker/) and [configuration reference](https://frankenphp.dev/docs/config/). Project integration checks pin FrankenPHP 1.13.5 with PHP 8.5.

Each request receives a new Context, request wrapper, and body cache. Middleware objects, handlers, and captured services may live throughout the worker's lifecycle. Create request-scoped services inside middleware/factories; shared services must not retain the current user or Context. PSR-15 alone does not guarantee that a third-party package is safe in a worker. Lifecycle separation and DI are described in [Context extensions](context-extensions.md).

## Reproducible transport checks

After `composer install`:

```sh
tools/runtime/test-fpm.sh
FRANKENPHP_BINARY=/absolute/path/to/frankenphp tools/runtime/test-worker.sh
```

The FPM script uses Docker Compose, PHP 8.5, and Caddy images pinned by digest; the worker script requires FrankenPHP 1.13.5/PHP 8.5. Both require the Python 3 standard library and stop the servers they start after checking. Results and logs are saved to `var/runtime-*-result.json` and `var/runtime-*.log`.

Checks cover multipart/file uploads, PHP size limits, HEAD/204/205, separate cookies, delivery of the first chunk before the producer finishes, errors after headers, and cancellation on client disconnect. The worker additionally runs 100 sequential probes alternating errors/401/422, third-party PSR-15 middleware, typed Identity/Tenant scope, constructor DI, a request factory, and an explicit PSR mapper. WeakReference checks ensure that earlier Context, identity, and scope objects are released; validation isolation, cleanup, and multiple restarts with `maxRequests=20` are also checked.

For a longer check without restarts:

```sh
FRANKENPHP_BINARY=/absolute/path/to/frankenphp \
PHP_HONO_TEST_PROBES=5000 \
PHP_HONO_TEST_MAX_REQUESTS=25000 \
PHP_HONO_TEST_SINGLE_BOOT=1 \
PHP_HONO_TEST_RESULT=var/runtime-soak-result.json \
tools/runtime/test-worker.sh
```

One probe includes a successful request and rejection checks: 5000 probes produce more than 18,000 HTTP requests. The 25,000-request limit therefore keeps one worker alive for the entire run. `PHP_HONO_TEST_SINGLE_BOOT=1` requires exactly one boot; the ordinary mode instead checks restarts. `check.py` also accepts `--probes` and `--single-boot` when run manually against an existing server. HTTP keep-alive allows a long run without exhausting the client's TCP ports.

The result includes request/boot counts, first and last PHP memory measurements, the memory range, and retained-object count. After the first ten probes, memory variation must remain below 1 MiB, and previous request objects must not be retained. This checks lifecycle behavior for the given scenario; it does not guarantee that every application is leak-free.

On FrankenPHP 1.13.5/PHP 8.5.12, this run revealed heap growth when cycles were collected only after `frankenphp_handle_request()`: 5000 probes increased memory from 771,616 to 1,908,736 bytes even though weak references were already empty. Moving GC inside the callback produced 18,346 HTTP requests in one boot with memory between 771,624 and 771,696 bytes. The comparison indicates that exception/request cycles need to be released before native input-stream cleanup; this fixes cleanup ordering in the adapter and does not claim to fix the upstream runtime. CI runs both the short restart test and this single-boot regression.
