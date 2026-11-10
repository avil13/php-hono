# Compatibility and API boundaries

**English** | [Русский](../ru/compatibility.md)

[Contents](README.md) · [Conformance manifest](../../tests/Fixtures/hono-v4.13.16/manifest.json)

The baseline is **Hono 4.13.16**, commit [`c91b60a251186fb024557c6d55a11d8e388f179c`](https://github.com/honojs/hono/tree/c91b60a251186fb024557c6d55a11d8e388f179c). PHP Hono is an independent port of selected HTTP contracts to PHP 8.5+. JavaScript Hono documentation may describe a different version and additional features.

## What the Hono comparison checks

The [fixture generator](../../tools/conformance/README.md) executes the pinned Hono source in Node. The repository stores 19 scenarios and 34 HTTP requests with upstream status, headers, body, and trace. PHPUnit reads them without Node or network access. Regeneration is deterministic; the generator verifies the commit, version, and absence of local source changes.

The corpus covers route ordering, middleware onion execution, optional/wildcard/segment regex patterns, parameters and percent decoding, HEAD/404, early responses, Response replacement, error handling, query/JSON/form data, and request isolation. Header names and their order are normalized; Content-Type may differ in whitespace after `;`. JSON is compared structurally while preserving the distinction between objects and arrays. Other body bytes and trace order are preserved.

The manifest classifies behavior as `compatible`, `intentional-difference`, or `unsupported`. For the difference after `$next()`, both the real upstream output and a separate PHP expectation are stored; they are not presented as the same result. The corpus does not imply full compatibility with every Hono export or test. Middleware security, resource cleanup, the PSR bridge, and runtime adapters also have their own PHP tests.

## Porting an application

| JavaScript Hono | PHP Hono |
| --- | --- |
| `new Hono()` | `new PhpHono\Hono()` |
| `app.get('/x', c => c.text('OK'))` | `$app->get('/x', fn (Context $c) => $c->text('OK'))` |
| `await next()` | Synchronous `$next()` |
| Fetch Request/Response | PSR-7 ServerRequest/Response and the `$c->req` wrapper |
| `c.req.raw` | `$c->req->raw()` |
| `c.res` / assignment | `$c->response()` / `$c->setResponse($response)` |
| Missing value `undefined` | `null` |
| JSON object | `stdClass`; a JSON array becomes a PHP array |
| Runtime bindings | Request env; browser/edge execution context is not emulated |
| TypeScript route inference / `hc` | No direct equivalent |

Routes and middleware execute in registration order; static routes have no implicit priority. `route()` imports a snapshot of child routes; `basePath()` shares the registration table. HEAD runs the GET chain with the original request method and removes the response body. A method miss defaults to 404. `query(name)` takes the first occurrence, `queries(name)` takes all occurrences, and urlencoded `parseBody()` defaults to the last occurrence. Headers are case-insensitive; Set-Cookie values remain separate.

## Intentional differences and limitations

| Area | PHP Hono 1.0 rule |
| --- | --- |
| Registration | Path is required; no implicit previous path; the table freezes after the first dispatch |
| Route grammar | Static, `:id`, terminal `:id?`, global/terminal wildcard, limited single-segment regex |
| Unsupported patterns | No middle/suffix wildcard, multiple optional segments, cross-segment regex, capturing/lookaround/backreferences, or full JS RegExp; registration fails explicitly |
| Params after next | The calling handler's parameters are restored; upstream Hono keeps the last downstream route's parameters |
| `strict: false` | Trailing-slash normalization applies to matching; `req->path()` preserves the original encoded URI path, such as `/about/` |
| Invalid URI | Malformed percent encoding/UTF-8 produces 400; exceeding the default 8192-byte path limit produces 414 |
| PCRE | Match limit 10,000, depth limit 1,000; exceeding a limit produces an application error, not 404 |
| Handler without a result | No Response, `setResponse()`, or next produces 500, regardless of the number of handlers |
| Response replacement | Explicit policy for header inheritance and Set-Cookie replacement; see [Context](context-response.md) |
| Typed extensions | `extension(Type::class)` checks the object under the exact key; no autowiring or search for an interface implementation |
| JSON and query keys | Objects are stdClass; integers outside PHP's integer range become strings; integer-like PHP array keys may be converted to int |
| Body buffering | Default limit 8 MiB, configured with `maxBodySize`; BodyLimit checks the available raw body before the endpoint |
| Multipart | Already parsed SAPI/PSR data; PHP normalizes names/repetitions; `all=true` and the dot option are unsupported |
| CORS | Only OPTIONS with Origin and Access-Control-Request-Method is preflight; ordinary OPTIONS delegates downstream; no ACAO without Origin, including wildcard configuration |
| CORS credentials | Wildcard + credentials is forbidden; the policy also applies after the endpoint, preventing endpoint CORS headers from bypassing the allowlist |
| Request ID | Random 128-bit ID represented by 32 hex characters; incoming IDs undergo the configured validation, and need not be UUIDs |
| ETag | SHA-256; HEAD does not read the body to create a new tag; streaming/nonseekable bodies are skipped |
| Compression | Gzip/ext-zlib for finite bodies; q negotiation; explicit identity ban → 406 if gzip is inapplicable; streaming is skipped |
| Error in onError | One fallback 500 without recursively calling the user handler |

CORS and Request ID differences are covered by PHP tests; they are not part of the upstream-generated HTTP corpus. Detailed rules are in [core middleware](builtin-middleware.md) and [HTTP middleware 1.0](advanced-middleware.md).

## Third-party middleware and dependencies

App implements PSR-15 `RequestHandlerInterface`. `Psr15::wrap()` adapts an existing `MiddlewareInterface` to the Hono chain, including body replay, request attributes, early responses, and exact preservation of the returned Response. Test dependencies pin `middlewares/uuid` 2.1.0 and `middlewares/response-time` 2.1.0; see [integration and boundaries](middleware.md#psr-15-middleware).

URI/method rewriting belongs in an external PSR pipeline before App: the route has already been selected inside the bridge. Repeated downstream calls are forbidden. Hono's `onError` converts endpoint exceptions, so third-party exception middleware does not replace this mechanism. Laravel/Symfony-native middleware and the old double-pass API are not adapted automatically.

PSR attributes and Context variables are not synchronized automatically. A mapper after the PSR middleware reads the forwarded request and registers a typed extension. Variables are not rolled back when the upstream request view is restored. The core has no `App::container()`, class-string resolver, `contextFactory/stateFactory`, `__get`, macros, or Context subclassing. See [Context extensions](context-extensions.md).

## Scope of 1.0

Implemented features include routing, Request/Context/Response, errors, typed extensions, the PSR bridge, core and additional HTTP middleware, the SAPI emitter, streaming/SSE, and the FrankenPHP adapter. Tested environments and deployment rules are described in [deployment](deployment.md). An adapter's existence alone does not guarantee compatibility with every server version or third-party container.

JSX and WebSocket are excluded by user request. A full raw multipart parser, JWT/JWK, a response cache store, additional runtimes, and client code generation are outside 1.0. PHP port performance is measured separately; JavaScript benchmark results and claims of outperforming every PHP framework do not carry over.

Changing the baseline requires updating the generator, fixtures, manifest, and difference documentation. See [fixture regeneration](../../tools/conformance/README.md). Original design decisions are recorded in the [specification (Russian)](../../spec/02-specification.md).
