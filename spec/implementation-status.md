# Реализация 1.0 и приёмка

Обновлено 10 ноября 2026. Реализован выбранный объём **32 задач** для PHP 8.5: дополнительные middleware, streaming/SSE и FrankenPHP worker включены. JSX/WebSocket исключены. Пакет `php-hono/php-hono`, namespace `PhpHono`; tag и внешняя публикация не выполнялись.

[Машиночитаемый отчёт](verification-2026-11-10.json) содержит версии, source fingerprint и результаты HTTP-прогонов. Исторические оценки сохранены в roadmap; они не подменяют фактические результаты проверок.

## Проверки

- `composer validate --strict`, `composer audit --locked`, `composer check` прошли на **PHP 8.5.12**. Mago **1.59.0**: форматирование корректно, warnings/errors отсутствуют; help-подсказки не блокируют выбранный профиль. PHPStan **max**, включая examples/benchmarks/tools, проходит без общего baseline.
- **228 PHPUnit-тестов, 2400 assertions**: unit, offline conformance, PSR integration, lifecycle, реальный CLI HTTP и disposable Git repositories. Полные примеры PHPUnit из документации отдельно прошли: 6 тестов, 10 assertions.
- Hono baseline **4.13.16**, commit `c91b60a251186fb024557c6d55a11d8e388f179c`: **19 сценариев / 34 HTTP-запроса**, полученных выполнением настоящего upstream. Повторная генерация детерминирована. Поддержка всех exports Hono не заявляется.
- Реальные `middlewares/uuid` **2.1.0** и `middlewares/response-time` **2.1.0** работают через bridge. Альтернативные HTTP messages/factories проверены с Guzzle PSR-7 **2.13.2**, default Nyholm **1.8.2**.
- FPM: PHP **8.5.12** + Caddy **2.11.7** в закреплённых Docker images. Worker: FrankenPHP **1.13.5** / PHP **8.5.12**. Проверены chunks до завершения producer, HEAD/204/205, несколько Set-Cookie, uploads/transport limits, ошибки после headers, отмена по disconnect, restart и изоляция typed state/validation.
- Streaming memory: **1 GiB** прочитан без накопления output; дополнительное выделение PHP страниц не превысило проверяемые 8 MiB. [Методика и результаты](../benchmarks/README.ru.md).
- Production ZIP проходит установку в отдельный чистый consumer через Composer **2.11.2** с `--no-dev`: framework + **6 runtime dependencies**, без dev tools. Проверены autoload, routing/JSON и PSR-15. В архив не входят vendor, caches, tests, tools, benchmarks и spec.

Длительный worker soak прошёл: **5000 probes, 18 346 HTTP-запросов, один boot**, PHP memory **771 624–771 696 bytes**, удержанных Context/Identity/Scope — **0**. Отдельный restart-прогон: 379 HTTP-запросов, 19 boots. Найденный рост памяти после исключений устранён переносом GC внутрь callback, до native teardown. Исходный дефект воспроизводился в том же сценарии и превышал неизменённый порог 1 MiB. Оба режима включены в CI.

Pre-commit дополнительно проверен с настоящим Mago 1.59.0: плохой index / исправленная рабочая копия отклонён по strict-types; правильный index / плохая рабочая копия принят; bytes index и рабочей копии сохранены. Пример Caddyfile из deployment проверен реальным запуском worker с JSON и SSE ответами.

CI настроен в `.github/workflows/ci.yml`: основной check, FPM/worker matrix, ограничение памяти streaming, clean package install. **Удалённый запуск GitHub Actions не наблюдался**; перечисленные результаты получены локально. Системный PHP на машине остался 8.4; проверки приложения выполнены в отдельном PHP 8.5 окружении. Глобальные установки PHP/Composer не менялись.

## Результат по задачам

| ID | Реализация | Проверка / материал |
| --- | --- | --- |
| T01 | Composer, Mago, PHPStan, PHPUnit, CI, read-only hook | `tests/Tooling`; реальные commits в disposable repos, partial staging в обе стороны, platform pin, сохранение hooks |
| T02 | Закреплённый baseline и generator | `tools/conformance`, `tests/Contract`, manifest с явными differences |
| T03 | PSR response factories и builders | `ResponseFactoryTest`; Nyholm и Guzzle, encoding failure, header injection |
| T04 | Path/params/query/headers | `HonoRequestTest`, `OrderedRouterTest`, upstream fixtures |
| T05 | BodyStore, text/json/forms/uploads | `BodyStoreTest`, `HonoRequestTest`; bounded non-seekable replay, malformed input |
| T06 | Context и typed extensions | `ContextTest`, `LifecycleTest`, `examples/context-di.php`; exact interface keys, identity, отсутствие auto-construction |
| T07 | OrderedRouter и grammar | `OrderedRouterTest`; freeze, ordering, unsupported regex, limits |
| T08 | Registration, groups, constructor DI | `HonoTest`, `LifecycleTest`; snapshot import, shared basePath, bootstrap-only container |
| T09 | Compose/onion/finalization | `HonoTest`, conformance; double-next, early return, replacement |
| T10 | Dispatch/errors/HEAD/isolation | `HonoTest`, `ContextTest`; lowercase methods, failing onError, bodyless responses |
| T11 | SAPI adapter/emitter | `RuntimeTest`, `SapiEmitterTest`, real FPM; uploads, forwarded policy, sent headers, stalled stream |
| T12 | In-memory request helper | `HonoTest`, `ResponseFactoryTest`; URL без сети, options, explicit unsupported options |
| T13 | Validator | `CoreMiddlewareTest`; targets, normalized data, early rejection, isolation |
| T14 | BodyLimit | `CoreMiddlewareTest`, `MiddlewareStackTest`; actual bytes, bounded read, PSR chain |
| T15 | Cookie helpers | `CookieTest`; values, attributes, deletion, header injection, multiple cookies |
| T16 | CORS | `CoreMiddlewareTest`, `MiddlewareStackTest`; preflight, allowlist, credentials, downstream errors |
| T17 | SecureHeaders | `CoreMiddlewareTest`; defaults/overrides/remove, сохранение cookies |
| T18 | RequestId и Logger | `CoreMiddlewareTest`; crypto ID, monotonic duration, sink failure policy |
| T19 | Комплексная приёмка MVP | Full suite, conformance, real FPM, mixed BodyLimit/PSR and Context lifecycle |
| T20 | Документация и package foundation | README, docs, examples, MIT/attribution; 0.1 отдельно не публиковалась |
| T21 | PSR-15 bridge | `Psr15Test`, `LifecycleTest`, `MiddlewareStackTest`; два реальных пакета, replay/removal/rewrite/error boundaries |
| T22 | Basic/Bearer auth | `AdvancedMiddlewareTest`; callback, challenge, malformed credentials, safe comparison |
| T23 | CSRF | `AdvancedMiddlewareTest`, `MiddlewareStackTest`; Origin/Fetch Metadata, safe/unsafe methods |
| T24 | ETag | `AdvancedMiddlewareTest`; weak/strong, conditional GET/HEAD, no-read streaming |
| T25 | Gzip compression | `AdvancedMiddlewareTest`; q negotiation, 406, ETag order, stream cleanup, unavailable gzip API |
| T26 | StaticFiles | `StaticFilesTest`; traversal, encoded separators, symlink boundary, HEAD, failed factory cleanup |
| T27 | 405/Allow и trailing slash | `AdvancedMiddlewareTest`; use/all, configured path limit, query-preserving redirects |
| T28 | Streaming/SSE | `StreamingTest`, real HTTP matrix, memory benchmark; framing injection, lazy producer, cleanup/disconnect |
| T29 | FrankenPHP worker | Restart, typed lifecycle и single-boot soak 18 346 запросов; память стабильна, GC до native teardown |
| T30 | Benchmarks и решение по router | `benchmarks/`; router/full dispatch/cold/FPM/worker/typed state/profile; алгоритм сохранён до доказанной оптимизации |
| T31 | Комплексная стабилизация | Targeted review исправил HEAD, path limit, stalled stream, SSE metadata injection и worker cleanup; full suite/FPM/worker проходят |
| T32 | Документация и подготовка выпуска | API reference, migration, SECURITY, CHANGELOG, чистый Composer archive/consumer; публикация отдельно |

## Принятые различия

Полный список находится в [compatibility](../docs/ru/compatibility.md) и manifest. Существенные уточнения при реализации:

- После `$next()` params восстанавливаются к вызывающему handler; raw `req->path()` сохраняется при `strict=false`.
- PSR-15 bridge предоставляет read-only replayable body из общего ограниченного кеша; URI/method rewrite и повторный downstream вызов внутри bridge запрещены. Pre-routing PSR pipeline может изменить запрос до Hono.
- Genuine CORS preflight требует Origin и Access-Control-Request-Method. Собственные CORS headers применяются согласно configured policy после downstream.
- ETag использует SHA-256; streaming/неперематываемое тело не читается для hash/gzip. Middleware порядок и HEAD policy документированы.
- Multipart использует SAPI parsed fields/uploads и transport marker; raw multipart parser не добавлен.
- Context extensions — PHP-specific typed store; DI собирает готовые callables, runtime container/autowiring не добавлены.

## Воспроизведение

```sh
composer install
composer tools:prepare
composer validate --strict
composer audit --locked
composer check
tools/runtime/test-fpm.sh
FRANKENPHP_BINARY=/absolute/path/to/frankenphp tools/runtime/test-worker.sh
php benchmarks/stream-memory.php
php tools/release/check-package.php
```

Версии, runtime configuration, ограничения и команды дополнительных прогонов описаны в [development](../docs/ru/development.md), [deployment](../docs/ru/deployment.md), [benchmark report](../benchmarks/README.ru.md). Локальный hook уже установлен для этого checkout: `core.hooksPath=.githooks`; рабочие файлы и Git index он не форматирует.

Для выпуска отдельно понадобятся review и Git tag/публикация. Remote CI, другие runtimes/OS, нагрузка production и сравнительный benchmark других фреймворков в эти локальные результаты не входят.
