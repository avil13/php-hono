# Совместимость и границы API

[English](../en/compatibility.md) | **Русский**

[Оглавление](README.md) · [Manifest conformance](../../tests/Fixtures/hono-v4.13.16/manifest.json)

Ориентир — **Hono 4.13.16**, commit [`c91b60a251186fb024557c6d55a11d8e388f179c`](https://github.com/honojs/hono/tree/c91b60a251186fb024557c6d55a11d8e388f179c). PHP Hono — самостоятельный порт выбранных HTTP-контрактов на PHP 8.5+. Документация JavaScript Hono может описывать другую версию и дополнительные возможности.

## Что проверяется сравнением с Hono

[Генератор fixtures](../../tools/conformance/README.ru.md) выполняет настоящие исходники закреплённого Hono в Node. В репозитории сохранены 19 сценариев и 34 HTTP-запроса с upstream status, headers, body и trace. PHPUnit читает их без Node и сети. Повторная генерация детерминирована; генератор проверяет commit, версию и отсутствие локальных изменений исходников.

Корпус покрывает порядок маршрутов, middleware onion, optional/wildcard/segment regex, параметры и percent decoding, HEAD/404, ранний ответ, замену Response, обработку ошибок, query/JSON/form и изоляцию запросов. Header names и их порядок нормализуются; для Content-Type допускается разница в пробеле после `;`. JSON сравнивается структурно с сохранением различия object/array. Остальные body bytes и порядок trace сохраняются.

Manifest классифицирует поведение как `compatible`, `intentional-difference` или `unsupported`. Для отличия после `$next()` сохранены и настоящий upstream output, и отдельное PHP expectation; они не выдаются за один результат. Корпус не означает полную совместимость со всеми exports или tests Hono. Middleware security, resource cleanup, PSR bridge и runtime adapters дополнительно проверяются собственными PHP-тестами.

## Как переносить приложение

| JavaScript Hono | PHP Hono |
| --- | --- |
| `new Hono()` | `new PhpHono\Hono()` |
| `app.get('/x', c => c.text('OK'))` | `$app->get('/x', fn (Context $c) => $c->text('OK'))` |
| `await next()` | Синхронный `$next()` |
| Fetch Request/Response | PSR-7 ServerRequest/Response и оболочка `$c->req` |
| `c.req.raw` | `$c->req->raw()` |
| `c.res` / присваивание | `$c->response()` / `$c->setResponse($response)` |
| Отсутствующее значение `undefined` | `null` |
| JSON object | `stdClass`; JSON array становится PHP array |
| Runtime bindings | Env запроса; browser/edge execution context не эмулируется |
| TypeScript route inference / `hc` | Прямого эквивалента нет |

Маршруты и middleware исполняются по регистрации; static route не имеет скрытого приоритета. `route()` импортирует snapshot child routes, `basePath()` разделяет таблицу регистрации. HEAD проходит GET chain с исходным request method и удалением тела ответа. Method miss по умолчанию — 404. `query(name)` берёт первое повторение, `queries(name)` — все; urlencoded `parseBody()` по умолчанию берёт последнее. Headers нечувствительны к регистру, Set-Cookie остаются отдельными значениями.

## Намеренные отличия и ограничения

| Область | Правило PHP Hono 1.0 |
| --- | --- |
| Регистрация | Path обязателен; нет неявного предыдущего path; таблица замораживается после первого dispatch |
| Route grammar | Static, `:id`, последний `:id?`, global/terminal wildcard, ограниченный regex одного сегмента |
| Unsupported patterns | Нет middle/suffix wildcard, нескольких optional segments, cross-segment regex, capturing/lookaround/backreferences и полного JS RegExp; ошибка регистрации явная |
| Params после next | Восстанавливаются параметры вызывающего handler; upstream Hono оставляет параметры последнего downstream route |
| `strict: false` | Нормализация завершающего slash применяется к matching; `req->path()` сохраняет исходный encoded URI path, например `/about/` |
| Некорректный URI | Malformed percent encoding/UTF-8 — 400; default path limit 8192 bytes — 414 |
| PCRE | Match limit 10 000, depth limit 1 000; превышение даёт ошибку приложения, а не 404 |
| Handler без результата | Без Response, `setResponse()` или next — 500 независимо от числа handlers |
| Response replacement | Явная политика наследования headers и замены Set-Cookie; [Context](context-response.md) |
| Typed extensions | `extension(Type::class)` проверяет объект под точным ключом; без autowiring и поиска реализации интерфейса |
| JSON и query keys | Объекты — stdClass; большие целые вне PHP integer — строки; integer-like keys PHP array могут приводиться к int |
| Body buffering | Default limit 8 MiB, задаётся `maxBodySize`; BodyLimit проверяет доступный raw body до endpoint |
| Multipart | Уже разобранные SAPI/PSR данные; PHP normalization имён/повторов; `all=true` и dot option не поддерживаются |
| CORS | Только OPTIONS с Origin и Access-Control-Request-Method считается preflight; обычный OPTIONS проходит дальше; без Origin нет ACAO, в том числе при wildcard |
| CORS credentials | Wildcard + credentials запрещены; policy применяется и после endpoint, поэтому endpoint не может обойти allowlist своими CORS headers |
| Request ID | Случайный 128-bit id в виде 32 hex symbols; входной id проходит заданную проверку, UUID не обязателен |
| ETag | SHA-256; HEAD не читает тело ради нового tag; streaming/nonseekable bodies пропускаются |
| Compression | Gzip/ext-zlib для конечных тел; q negotiation, явный identity ban → 406, если gzip неприменим; streaming пропускается |
| Ошибка onError | Один fallback 500 без рекурсивного вызова пользовательского handler |

CORS и Request ID отличия проверены PHP-тестами; они не входят в upstream-generated HTTP corpus. Подробные правила — в [базовых middleware](builtin-middleware.md) и [HTTP middleware 1.0](advanced-middleware.md).

## Сторонние middleware и зависимости

App реализует PSR-15 `RequestHandlerInterface`. `Psr15::wrap()` адаптирует готовый `MiddlewareInterface` к цепочке Hono, включая body replay, request attributes, ранние ответы и точное сохранение возвращённого Response. В тестовых зависимостях закреплены `middlewares/uuid` 2.1.0 и `middlewares/response-time` 2.1.0; [подключение и границы](middleware.md#psr-15-middleware).

URI/method rewrite выполняется во внешнем PSR pipeline перед App: внутри bridge route уже выбран. Повторные downstream вызовы запрещены. Endpoint exceptions преобразует Hono `onError`, поэтому сторонний exception middleware не заменяет этот механизм. Laravel/Symfony-native и старый double-pass API автоматически не адаптируются.

PSR attributes и Context variables не синхронизируются автоматически. Mapper после PSR middleware читает forwarded request и регистрирует typed extension. Variables не откатываются при восстановлении upstream request view. В ядре нет `App::container()`, class-string resolver, `contextFactory/stateFactory`, `__get`, macros или subclassing Context. [Расширение Context](context-extensions.md).

## Объём 1.0

Реализованы routing, Request/Context/Response, ошибки, typed extensions, PSR bridge, базовые и дополнительные HTTP middleware, SAPI emitter, streaming/SSE и FrankenPHP adapter. Матрица проверенных окружений и правила запуска находятся в [deployment](deployment.md). Наличие адаптера само по себе не является гарантией совместимости с любой версией сервера или сторонним container.

JSX и WebSocket исключены по требованию пользователя. Полный raw multipart parser, JWT/JWK, response cache store, дополнительные runtimes и client codegen не входят в 1.0. Производительность PHP-порта измеряется отдельно; результаты JavaScript benchmarks и обещание быть быстрее всех PHP-фреймворков сюда не переносятся.

При изменении baseline обновляются generator, fixtures, manifest и документация отличий. Инструкции: [регенерация fixtures](../../tools/conformance/README.ru.md). Исходные проектные решения: [spec](../../spec/02-specification.md).
