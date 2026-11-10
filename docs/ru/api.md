# Публичный API 1.0

[English](../en/api.md) | **Русский**

[Оглавление](overview.md). Ниже реализованный API выбранного PHP-профиля. Пространство имён — `PhpHono`; классы `Internal` и внутренние методы с `@internal` не являются стабильным расширяемым API.

## Hono

```php
new Hono(
    strict: true,
    env: [],
    maxBodySize: 8_388_608,
    maxPathLength: 8192,
    responseFactory: null,
    streamFactory: null,
    router: null,
);
```

Фабрики принимают PSR-17 interfaces, router — `Router\RouterInterface`. По умолчанию используются Nyholm и `OrderedRouter`. Лимит тела относится к буферизации reader/bridge; ограничения transport задаются отдельно. `handle()` использует `env` конструктора; `fetch()` и `request()` используют переданный им массив, по умолчанию пустой.

| Метод | Назначение |
| --- | --- |
| `get/post/put/patch/delete/options/query(path, ...handlers): static` | Регистрация метода; `query` означает HTTP QUERY |
| `on(methods, paths, ...handlers): static` | Строка или список методов/путей |
| `all(path, ...handlers): static` | Endpoint для всех методов |
| `use(path, ...middleware): static` | Middleware с явным path, обычно `*` |
| `basePath(prefix): Hono` | Представление с префиксом, общая таблица маршрутов |
| `route(prefix, child): static` | Снимок зарегистрированных маршрутов child |
| `onError(callable(Throwable, Context): ResponseInterface): static` | Обработчик ошибок |
| `notFound(callable(Context): ResponseInterface): static` | Обработчик 404 |
| `handle(ServerRequestInterface): ResponseInterface` | PSR-15 entry point |
| `fetch(ServerRequestInterface, env = []): ResponseInterface` | Dispatch с явным env |
| `request(string\|ServerRequestInterface, options = [], env = []): ResponseInterface` | Запрос в памяти; options: method, headers, body |
| `allowedMethods(path): list<string>` | Методы endpoints для opt-in 405; пустой список также означает unrestricted ALL |

Регистрация прекращается при первом dispatch. Все совпавшие handlers выполняются в порядке регистрации до раннего ответа. `$next(): ResponseInterface` можно вызвать один раз. После `$next()` изменяйте ответ через Context; простой `return $newResponse` уже финализированный ответ не заменяет.

## Request и Context

`$c->req` — `HonoRequest`, `$c->env` — readonly ссылка на массив окружения запроса, `$c->error` — обработанный Throwable или null.

| Request | Результат |
| --- | --- |
| `raw()` | Текущий `ServerRequestInterface` |
| `method()/path()/url()` | Строки; path сохраняет percent encoding |
| `param(name = null)` | Одно декодированное значение или карта параметров текущего handler |
| `query(name = null)` | Первое значение или карта первых значений |
| `queries(name = null)` | Список значений или карта списков |
| `header(name = null)` | Строка/null либо карта headers |
| `text()/json()` | Повторяемое чтение через общий ограниченный кеш |
| `parseBody(all = false)` | Form data; raw multipart не разбирается |
| `uploadedFiles()` | Дерево PSR uploaded files |
| `valid(target)` | Результат выполненного Validator; иначе LogicException |

| Context | Назначение |
| --- | --- |
| `set(key, value)/get(key)/has(key)` | Request-local store, различает missing и null через has |
| `extension(class-string<T>): T` | Проверка зарегистрированного объекта через instanceof, без создания |
| `status(code): void` | Default status для следующих helpers |
| `header(name, string\|list<string>\|null, append = false): void` | Задать, добавить или удалить header |
| `body(string\|StreamInterface = '', status = null, headers = [])` | Создать Response |
| `text/html/json(value, status = null, headers = [])` | Создать Response с соответствующим Content-Type |
| `redirect(url, status = 302)` | Пустой Response с Location |
| `notFound()` | Создать ответ через зарегистрированный notFound |
| `response(): ?ResponseInterface` | Текущий финализированный ответ |
| `setResponse(ResponseInterface): void` | Явная замена с документированной политикой наследования headers |

Response helpers возвращают `ResponseInterface` и сами не финализируют Context. Статусы 100–599; `HttpException` принимает только 400–599. Подробнее: [Request](request.md), [Context](context-response.md), [ошибки](errors.md).

## Middleware и helpers

Native middleware — invokable объекты `(Context, callable $next)`: они возвращают ResponseInterface/null либо имеют void, в зависимости от раннего ответа и post-processing. `$next(): ResponseInterface` возвращает downstream-ответ. Конструкторы и опции перечислены в [базовых](builtin-middleware.md) и [дополнительных middleware](advanced-middleware.md). `Middleware\Psr15::wrap(MiddlewareInterface): Closure` адаптирует готовый PSR-15 объект.

`Helper\Html::escape(string): string` экранирует HTML text/attribute values. `Helper\Cookie::{get,set,delete}` описаны в [Cookie](builtin-middleware.md).

`Helper\Streaming::{stream,streamText,streamSSE}(Context, callable $producer, ?int $status = null, array $headers = []): ResponseInterface`. Без явного статуса используется default Context. Первые два producer возвращают `iterable<string>`. SSE producer возвращает `iterable<array{data:string,event?:string,id?:string,retry?:int}>`. Производитель запускается при чтении тела; HEAD его не запускает. `Http\StreamingBody` — одноразовый non-seekable PSR stream.

## Runtime

`Adapter\Sapi::request(): ServerRequestInterface` создаёт запрос из globals. `Sapi::run(RequestHandlerInterface, ?SapiEmitter = null): void` получает запрос и отправляет ответ. `SapiEmitter(?callable $onError = null)` поддерживает `emit(ResponseInterface, bool $head = false): void` и закрывает body после отправки.

`Adapter\FrankenPhpWorker::run(RequestHandlerInterface, int $maxRequests = 500, ?callable $cleanup = null): void` работает только в FrankenPHP worker mode. Cleanup вызывается после каждого запроса; объекты приложения создаются до loop. Подробности владения ресурсами и конфигурации — [deployment](deployment.md).
