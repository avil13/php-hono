# Совместимость и границы API

[Оглавление](README.md) · Проектный профиль, ещё не подтверждённый исполняемым conformance suite.

Ориентир — **Hono 4.13.16**, commit [`c91b60a251186fb024557c6d55a11d8e388f179c`](https://github.com/honojs/hono/tree/c91b60a251186fb024557c6d55a11d8e388f179c). PHP Hono — самостоятельный порт выбранных HTTP-контрактов. Документация JavaScript Hono может описывать другую версию и возможности, которых здесь нет.

## Как переносить приложение

| JavaScript Hono | PHP Hono |
| --- | --- |
| `new Hono()` | `new Hono()` в предварительном namespace `PhpHono` |
| `app.get('/x', c => c.text('OK'))` | `$app->get('/x', fn (Context $c) => $c->text('OK'))` |
| `await next()` | `$next()`; синхронное выполнение |
| Fetch Request/Response | PSR-7 ServerRequest/Response и оболочка `$c->req` |
| `c.req.raw` | `$c->req->raw()` |
| `c.res` / присваивание | `$c->response()` / `$c->setResponse($response)` |
| `undefined` для отсутствующего значения | `null` |
| JS object из JSON | `stdClass`; JSON array становится PHP array |
| Runtime bindings | Env текущего запроса; browser/edge execution context не эмулируется |
| TypeScript route inference / `hc` | Прямого эквивалента нет |

Синтаксис переноса не гарантирует одинаковое поведение всех edge cases. Используйте тесты приложения и ограничения ниже.

## Что сохраняется в MVP

Маршруты и middleware исполняются по порядку регистрации. `$next()` создаёт порядок «до → downstream → после»; ранний Response останавливает цепочку. `route()` импортирует уже существующие child routes, `basePath()` разделяет таблицу регистрации. В обычном приложении HEAD проходит GET chain с сохранением исходного метода HEAD и удалением тела ответа. Method miss по умолчанию даёт 404.

`query(name)` берёт первое повторение, `queries(name)` — все. Urlencoded `parseBody()` по умолчанию берёт последнее повторение. Case-insensitive headers и отдельные Set-Cookie сохраняются.

## Намеренные отличия

| Область | Правило PHP Hono |
| --- | --- |
| Регистрация | Path обязателен; нет перегрузки с неявным предыдущим path |
| Route grammar | Static, `:id`, последний `:id?`, global/terminal wildcard, ограниченный regex одного сегмента |
| Расширенные patterns | Нет middle/suffix wildcard, нескольких optional segments, multi-segment regex и полного набора JS RegExp |
| Некорректный URI | Malformed percent encoding/UTF-8 даёт 400 |
| Длина path | Default limit 8192 bytes; превышение даёт 414 |
| Handler без результата | Если он не вернул/установил Response и не вызвал next — 500 независимо от числа handlers |
| Response replacement | Собственная политика наследования headers и замены Set-Cookie; см. [Context](context-response.md) |
| JSON | Объекты stdClass, большие целые вне диапазона PHP integer — строки; integer-like keys PHP array могут приводиться к int |
| Буфер тела | Конечный configurable лимит; 8 MiB пока предложено, окончательное значение закрепляется в T02 |
| Body limit | Фактический raw body проверяется до endpoint даже при наличии Content-Length |
| Multipart | Уже разобранные SAPI/PSR данные; PHP normalization имён/повторов; `all=true` и `dot:true` не поддерживаются |
| Ошибка onError | Один встроенный fallback 500 без рекурсивного вызова пользовательского handler |

## Что относится к какому этапу

| Возможность | Целевой этап |
| --- | --- |
| Routing, Context, Request, Response, error handling | MVP |
| FPM/SAPI, CLI server, in-memory request | MVP |
| Validator, body limit, cookies, CORS, secure headers, logger/request ID | MVP; точные factories/options уточняются |
| PSR-15 RequestHandlerInterface у App | MVP |
| Подключение стороннего PSR-15 MiddlewareInterface через bridge | Обязательный MVP; Psr15::wrap() и интеграции реальных пакетов |
| Basic/Bearer auth, CSRF, ETag, compression, optional 405/trailing slash, static files | 1.0 |
| FrankenPHP worker, HTTP streaming/SSE | 1.0 |
| Полный raw multipart, JWT/JWK, cache, дополнительные runtimes, client codegen | Возможный отдельный backlog; в 1.0 не обещаны |
| JSX и WebSocket | Исключены из проекта по требованию пользователя |

В этой таблице «MVP» означает запланированный объём, а не уже реализованную функцию. Пользовательские middleware можно описывать на общем callable API; копировать названия JS imports как готовые PHP-функции нельзя.

PSR-15 bridge гарантирует заявленный профиль request/response обработки, а не работу любого PHP middleware без условий. Laravel/Symfony-native и double-pass API не адаптируются автоматически. Повторные downstream вызовы не поддерживаются; перехват endpoint exceptions выполняется через Hono onError. URI/method rewrite размещается перед App во внешнем PSR pipeline, а не внутри уже выбранного маршрута. Body для bridge может буферизоваться с лимитом. Совместимость конкретных версий сторонних пакетов должна подтверждаться на PHP 8.5; [подробности подключения](middleware.md#psr-15-middleware).

## Принцип расширения

Неподдерживаемые options и route patterns должны давать явную ошибку, а не имитировать успех. Изменение baseline Hono сопровождается обновлением fixtures и документации различий. Производительность PHP-порта будет измеряться отдельно; численные обещания из JavaScript-бенчмарков сюда не переносятся.

Детальный контракт и критерии приёмки находятся в [spec](../spec/02-specification.md), а отложенные направления — в [плане](../spec/04-roadmap.md).
