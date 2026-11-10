# Changelog

**English** | [Русский](CHANGELOG.ru.md)

## 1.0.0 — prepared, not published

- PHP 8.5, PSR-7/17 HTTP messages, Hono-style ordered routing, and synchronous middleware.
- Context extensions, constructor DI, request-local state, bounded request readers, validation, and HTTP errors.
- A PSR-15 bridge that restores the request view; integrations with middlewares/uuid and middlewares/response-time.
- Cookies, CORS, secure headers, request IDs, logging, body limits, Basic/Bearer auth, CSRF, ETag, gzip, static files, opt-in 405 responses, and trailing-slash redirects.
- SAPI/FPM, deferred streaming/SSE, and a FrankenPHP worker adapter.
- Mago 1.59.0, PHPStan max, PHPUnit, staged-snapshot pre-commit checks, CI, and pinned upstream fixtures.

Version 0.1 was not released separately. The [migration guide](docs/en/migration.md) lists the final API names and limitations. SemVer applies to the documented API after the 1.0 release; Internal classes, benchmark/test tooling, and internal methods are outside the stable contract.
