# Context extensions and dependencies

**English** | [Русский](../ru/context-extensions.md)

php-hono 1.0 API for PHP 8.5. [Contents](overview.md).

State for the current request lives in ordinary application objects. Middleware creates an object, stores it in Context with `set()`, and a handler retrieves it with a type check through `extension()`. This is composition: adding your own fields does not require extending the Context class.

## Typed request state

```php
use PhpHono\Context;
use PhpHono\Hono;
use Psr\Http\Message\ResponseInterface;

final readonly class AuthContext
{
    public function __construct(public ?string $userId) {}
}

$app = new Hono();
$app->use('*', function (Context $c, callable $next): void {
    // An anonymous user for this example, not an authentication implementation.
    $c->set(AuthContext::class, new AuthContext(userId: null));
    $next();
});
$app->get('/me', function (Context $c): ResponseInterface {
    $auth = $c->extension(AuthContext::class);

    if ($auth->userId === null) {
        return $c->json(['error' => 'Authentication required'], 401);
    }

    return $c->json(['id' => $auth->userId]);
});
```

`AuthContext` is an application class. Real authentication middleware obtains the identifier from a verified authentication result. Do not treat unchecked query/header values as a verified identity.

The middleware that provides the object must run before the handler that consumes it. Otherwise, `extension()` detects the missing object and throws `LogicException`. During dispatch, the exception goes to the existing `onError`; an error thrown by `onError` itself goes through the existing 500 fallback. Outside dispatch, it is an ordinary exception passed to the caller. A dependency configuration error is distinct from an ordinary anonymous user: the latter has an object with `userId === null`.

## The extension contract

The implemented [Context::extension](https://github.com/avil13/php-hono/blob/master/src/Context.php) signature and PHPDoc:

```text
/**
 * @template T of object
 * @param class-string<T> $type
 * @return T
 */
public function extension(string $type): object;
```

PHP checks the returned `object`, while generic PHPDoc lets a static analyzer infer the concrete type from `AuthContext::class`. This is a PHPDoc contract, not native PHP generics. [PHPStan's explanation of generics](https://phpstan.org/blog/generics-in-php-using-phpdocs).

Method rules:

- It reads **the same exact case-sensitive key** `$type` in the variables store used by `set/get/has`. There is no separate extension store.
- The value must be an object satisfying `instanceof $type`. A missing key, null, a scalar or an object of another type causes `LogicException`.
- It returns the same instance. The method does not create or clone an object, look up a service or run autowiring.
- Calling `set($type, $other)` again changes the result of the next `extension($type)` call: there is no separate memoization cache.
- PHP may resolve class names case-insensitively, but Context keys remain exact strings. Use `SomeType::class` consistently rather than manually changing case.

`set/get/has` keep their existing behavior: they accept arbitrary values without adding this type check. For example, `set(AuthContext::class, null)` is valid; a subsequent `get()` returns null, `has()` returns true, and `extension()` throws an exception.

Interfaces are registered explicitly. If `AuthContext` implements your `Principal`, store the object under `Principal::class`:

```php
// $auth implements the Principal interface defined by the application.
$c->set(Principal::class, $auth);
$principal = $c->extension(Principal::class);
```

Storing it only under `AuthContext::class` does not make it available under `Principal::class`. Storing it under both keys is allowed, but replacing the value of one key does not automatically change the other.

## Constructor DI for handlers and middleware

Build callable objects during bootstrap. Pass dependencies through ordinary constructors; Context provides data for the current request.

The example uses `AuthContext`, `$app` and the imports from the first block. `AuthLookup` and the following classes belong to the application:

```php
use Psr\Http\Message\ServerRequestInterface;

interface AuthLookup
{
    public function userId(ServerRequestInterface $request): ?string;
}

final readonly class AttachAuth
{
    public function __construct(private AuthLookup $lookup) {}

    public function __invoke(Context $c, callable $next): void
    {
        $auth = new AuthContext($this->lookup->userId($c->req->raw()));
        $c->set(AuthContext::class, $auth);
        $next();
    }
}

final readonly class WhoAmI
{
    public function __construct(private string $applicationName) {}

    public function __invoke(Context $c): ResponseInterface
    {
        $auth = $c->extension(AuthContext::class);
        return $c->json([
            'application' => $this->applicationName,
            'userId' => $auth->userId,
        ]);
    }
}

/** @var AuthLookup $lookup An implementation from the application bootstrap. */
$app->use('/api/*', new AttachAuth($lookup));
$app->get('/api/me', new WhoAmI('Example API'));
```

In this example, `AuthLookup` can be shared between requests only if its lifecycle permits it: it must not retain the current user in its own state. `AuthContext` itself is created afresh inside `__invoke()`. Declaring the middleware `readonly` prevents replacing its reference but does not automatically make the injected service immutable.

## An optional bootstrap container

The application may use any suitable [PSR-11 container](https://www.php-fig.org/psr/psr-11/) to assemble its objects. This is an application decision: php-hono's core does not require a runtime dependency on `psr/container`.

```php
/** @var \Psr\Container\ContainerInterface $container */
$authMiddleware = $container->get(AttachAuth::class);
if (!$authMiddleware instanceof AttachAuth) {
    throw new \LogicException('The container must return AttachAuth');
}

$app->use('/api/*', $authMiddleware);
$app->get('/api/me', new WhoAmI('Example API'));
```

This registration is an alternative to the previous block. The container is used during bootstrap and is not passed into Context for arbitrary service lookup during a request. It returns a ready-to-use callable; `use/get` do not automatically resolve class strings through the container.

`Context` is final. The API has no `App::container()`, class-string resolver, `contextFactory/stateFactory`, `__get` or macros. Use composition, constructor DI and explicit `set/extension` calls.

## Lifecycle and resource cleanup

Each request in FPM and each App invocation in a persistent worker gets a new Context. Registered handlers and the services they capture, however, may be shared. For request-local state, call a factory **inside** the middleware/handler. The factory must return a new request object rather than cache it as a singleton.

The following is a separate application example for managing an unfinished transaction; `$openConnection` is a factory returning a separate PDO connection with suitable configuration. Register lifecycle middleware before all endpoints that need this connection:

```php
/** @var \Closure(): \PDO $openConnection */
$lifecycleApp = new Hono();
$lifecycleApp->use('/api/*', function (Context $c, callable $next) use ($openConnection): void {
    $db = $openConnection();
    $c->set(\PDO::class, $db);

    try {
        $next();
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
});
// Register the relevant endpoints in $lifecycleApp after this middleware.
```

A successful transaction is explicitly committed by the service that knows the outcome of the operation. Do not commit unconditionally after `$next()`: a downstream exception may already have been converted to a Response by `onError`. Even the HTTP status alone does not prove that the business operation succeeded. `finally` closes unfinished work; explicitly release other resources through the relevant service's API.

An object stored in Context remains available until the request ends. Do not retain Context or request objects in static/global state, a singleton or a captured reference between requests. In tests, call **the same App** repeatedly to detect state leakage.

## PSR-15 attributes and typed extensions

PSR request attributes and Context variables are separate stores. They are not synchronized automatically. Place an explicit mapper after the PSR middleware to read **the current forwarded request** through `$c->req->raw()`:

```php
use PhpHono\Middleware\Psr15;

/** @var \Psr\Http\Server\MiddlewareInterface $authentication */
$psrApp = new Hono();
$psrApp->use('/api/*', Psr15::wrap($authentication));
$psrApp->use('/api/*', function (Context $c, callable $next): void {
    $auth = $c->req->raw()->getAttribute(AuthContext::class);
    if (!$auth instanceof AuthContext) {
        // A repeated mapper must not leave the previous AuthContext in place.
        $c->set(AuthContext::class, null);
        throw new \LogicException('Auth middleware must provide AuthContext');
    }

    $c->set(AuthContext::class, $auth);
    $next();
});
$psrApp->get('/api/me', new WhoAmI('Example API'));
```

The example assumes an application contract: `$authentication` forwards an `AuthContext` object as a PSR attribute under that name. If the library uses another key or type, the mapper converts it explicitly. If the attribute is missing or removed, first clear the previous value with `set(AuthContext::class, null)`, then report a contract error or return 401 for an ordinary unauthenticated request. Clearing is required in both branches: an early 401 must not leave the previous identity available to upstream middleware. A repeated mapper in the same request must not silently preserve an earlier AuthContext. This is an alternative to `AttachAuth`, not a second mandatory authentication middleware.

When the bridge exits, upstream middleware sees its own raw request again. Context variables remain shared for that request: a value written by the mapper is not rolled back along with the request view. The order PSR middleware → mapper → consumer is required. If the chain short-circuits before the mapper, the mapper does not run and previously set variables remain; an early response does not clear Context by itself.

## Runnable example

[examples/context-di.php](https://github.com/avil13/php-hono/blob/master/examples/context-di.php) returns a configured `Hono`: `AttachTenant` receives a request factory through its constructor, `HelloTenant` receives configuration through its constructor, and an explicit mapper transfers `RequestMetadata` from a PSR attribute to an extension. The example classes are in [examples/ContextDi](https://github.com/avil13/php-hono/tree/master/examples/ContextDi); no external container is needed. The tenant comes from trusted bootstrap configuration, while a new request ID is created for each invocation.

From the project root after installing Composer dependencies:

```sh
php -r '$app = require "examples/context-di.php"; echo $app->request("/whoami")->getBody();'
```

The response contains `message: "Hello, tenant example"` and a random `requestId`. Calling the same App a second time produces a different identifier. To serve HTTP, use the returned application with the [SAPI adapter](deployment.md).

Next: [middleware](middleware.md), [Context and responses](context-response.md), [testing](testing.md), [deployment](deployment.md).
