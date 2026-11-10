# Changelog

## 1.0.0 — подготовлено, не опубликовано

- PHP 8.5, PSR-7/17 HTTP messages, Hono-style ordered routing и synchronous middleware.
- Context extensions, constructor DI, request-local state, bounded request readers, validation и HTTP errors.
- PSR-15 bridge с восстановлением request view; интеграции middlewares/uuid и middlewares/response-time.
- Cookies, CORS, secure headers, request ID, logger, body limits, Basic/Bearer auth, CSRF, ETag, gzip, static files, opt-in 405 и trailing slash.
- SAPI/FPM, deferred streaming/SSE и FrankenPHP worker adapter.
- Mago 1.59.0, PHPStan max, PHPUnit, staged snapshot pre-commit, CI и pinned upstream fixtures.

Версия 0.1 отдельно не выпускалась. [Миграция с проектного API](docs/migration.md) перечисляет окончательные имена и ограничения. SemVer применяется к документированному API после выпуска 1.0; Internal-классы, benchmark/test tooling и внутренние методы не входят в стабильный контракт.
