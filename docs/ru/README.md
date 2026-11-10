# PHP Hono

[English](../en/README.md) | **Русский**

PHP Hono — HTTP-фреймворк для **PHP 8.5+** с маршрутизацией и middleware в стиле Hono. Обработчик получает Context и возвращает PSR-7 Response; приложение можно вызывать в памяти или подключать к HTTP-серверу через адаптер.

**Статус: реализация выбранного профиля 1.0.** Пакет `php-hono/php-hono`, namespace `PhpHono`. Исходники, примеры, строгие проверки, PSR-15 bridge, middleware, streaming и worker находятся в репозитории. Внешняя публикация не выполнялась. [Статус задач и проверки](../../spec/implementation-status.md).

```php
use PhpHono\Context;
use PhpHono\Hono;

$app = new Hono();
$app->get('/', fn (Context $c) => $c->text('Hello, PHP Hono!'));
$app->get('/users/:id', fn (Context $c) =>
    $c->json(['id' => $c->req->param('id')])
);
```

## Руководство

| Раздел | Что вы узнаете |
| --- | --- |
| [Начало работы](getting-started.md) | Требования, структура приложения, первый API и вызов без сервера |
| [Маршрутизация](routing.md) | HTTP-методы, параметры, wildcard, группы, порядок регистрации |
| [Middleware](middleware.md) | Код до и после обработчика, ранний ответ, Context и встроенные возможности |
| [Запрос](request.md) | Query, headers, JSON, формы, загрузка файлов |
| [Context и ответы](context-response.md) | Text/JSON/HTML, статусы, headers, cookies, замена Response |
| [Расширение Context и зависимости](context-extensions.md) | Typed request state, constructor DI, bootstrap-контейнер и lifecycle |
| [Ошибки](errors.md) | 404, onError, HTTP-ошибки и диагностика |
| [Тестирование](testing.md) | PHPUnit, запросы в памяти, datasets и проверка изоляции |
| [Разработка и проверки](development.md) | Mago, строгий lint и pre-commit проверки |
| [Запуск](deployment.md) | PSR-7 вход, SAPI/FPM, локальный сервер, FrankenPHP workers и SSE |
| [Совместимость](compatibility.md) | Отличия от JavaScript Hono, реализованный профиль и ограничения |

Начните с [первого приложения](getting-started.md), затем прочитайте про [порядок middleware](middleware.md). Он определяет, какие обработчики будут выполнены и какой ответ получит клиент.

## Основные правила

- Каждый запрос получает новый Context. Данные текущего запроса храните через `set/get/has`; типизированные объекты получайте через `extension(Type::class)`, не через глобальные переменные.
- Готовые handlers/middleware собираются через constructor DI; необязательный PSR-11 контейнер используется только при bootstrap. В ядре нет autowiring или service locator.
- Обработчик возвращает Response. Middleware либо возвращает ранний Response, либо вызывает `$next()` ровно один раз.
- Регистрация маршрутов заканчивается до первого запроса. После начала dispatch таблица маршрутов замораживается.
- `request()` выполняет приложение в памяти. `handle()` принимает PSR-7 ServerRequest. Ни один из этих методов сам не отправляет ответ в сеть.
- Готовые сторонние PSR-15 middleware подключаются через `Psr15::wrap()`; [пример и ограничения](middleware.md#psr-15-middleware).
- JSX и WebSocket исключены из проекта. HTML-ответы остаются; HTTP streaming/SSE реализованы.

## Справочники

- [Публичный API](api.md).
- [Базовые middleware и cookies](builtin-middleware.md).
- [Auth, CSRF, ETag, gzip, files, 405 и trailing slash](advanced-middleware.md).
- [Переход от проекта 0.1 к 1.0](migration.md).
- [Benchmark baseline](../../benchmarks/README.ru.md).

Источники решений — [спецификация](../../spec/02-specification.md) и [статус реализации](../../spec/implementation-status.md). Поддержка всего набора exports Hono не заявляется.
