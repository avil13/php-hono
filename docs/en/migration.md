# Moving from the 0.1 plan to 1.0

**English** | [Русский](../ru/migration.md)

Version 0.1 existed as a design contract; there is no published package or data to migrate. The final package name is `php-hono/php-hono`, with the `PhpHono` namespace. PHP versions below 8.5 are unsupported.

Compare experimental code with the [API reference](api.md):

- Configuration is passed as named arguments to `new Hono(...)`; response factories implement PSR-17.
- The front controller calls `Adapter\Sapi::run($app)`. The worker calls `Adapter\FrankenPhpWorker::run($app)`.
- `HttpException($status, $message = '', $response = null, $previous = null)` exposes readonly `$status` and `$response` properties.
- Built-in middleware consists of `PhpHono\Middleware` classes constructed with `new`. PSR-15 middleware connects through `Psr15::wrap()`.
- HTML escaping uses `Helper\Html::escape()`, cookies use `Helper\Cookie`, and streaming uses `Helper\Streaming`.
- `Context::extension(Type::class)` retrieves the object stored under the same exact `set()` key. There is no automatic construction, service lookup, or PSR attribute synchronization.
- After `$next()`, params return to those of the calling handler. This is an intentional difference from upstream Hono.

After updating dependencies, run `composer check` and real HTTP tests for your runtime. Restart long-running workers after changing the application. Publishing a release/tag is separate from preparing the source code.
