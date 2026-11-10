# Спецификация PHP Hono

Выбранный профиль реализован 10 ноября 2026. Окончательные PHP-сигнатуры — [API reference](../docs/ru/api.md), доказательства проверок — [статус реализации](implementation-status.md). Историческое разделение MVP/1.0 сохранено для трассировки задач.

## Цель и границы

Создать небольшой HTTP-фреймворк на **PHP 8.5+**: выразительная регистрация маршрутов, композиция middleware, удобный Context, минимум конфигурации и тестирование без веб-сервера. Поддерживаемый профиль поведения основан на Hono 4.13.16; [источники](01-research.md).

Нормативные требования ниже относятся к PHP-порту. Они не означают, что весь Hono ведёт себя точно так же. Идентификаторы `C-*` используются для тестов и приёмки задач. **JSX и WebSocket не нужны по решению пользователя: они исключены полностью, а не отложены на следующую версию.** HTML-ответы и HTTP streaming/SSE от этого не исключаются.

| Область | MVP / 0.1 | Дополнение до 1.0 | За пределами 1.0 |
| --- | --- | --- | --- |
| HTTP | PSR-7, Context, Request, ответы, errors/404/HEAD, обязательный PSR-15 middleware bridge | Стабилизация интеграций | Полный Fetch API |
| Расширения и зависимости | Объекты состояния через Context::extension(), constructor injection, внешний PSR-11 при сборке приложения | Проверка lifecycle в worker | Встроенный контейнер, autowiring/resolver, фабрика или наследование Context как публичный extension API |
| Routing | Методы, параметры, terminal wildcard, optional terminal param, ограниченный regex, route/basePath | Измерение и оптимизация без изменения профиля | Полный набор шаблонов Hono, router presets |
| Body | JSON/text/urlencoded; multipart из PSR/SAPI с ограничениями | Укрепление текущего контракта | Полный raw multipart parser и `dot:true` |
| Middleware | Validator, bodyLimit, cookies, CORS, secureHeaders, logger, requestId | Basic/Bearer, CSRF, ETag, compress, 405, trailing slash | JWT/JWK, IP restriction, cache, timeout, context-storage и остальные |
| Runtime | PHP-FPM, CLI server, запрос в памяти | FrankenPHP worker, streaming/SSE | RoadRunner, Swoole, ReactPHP, serverless |
| DX | Composer, PHPDoc, examples, PHPUnit, PHPStan max, Mago format/lint со строгими правилами, read-only pre-commit и CI | Стабильный API и migration guide | TS RPC, SSG, OpenAPI/client generator |

## Проект публичного API

Namespace `PhpHono` предварительный. Все сниппеты показывают целевой API, а не существующую реализацию.

```php
use PhpHono\Context;
use PhpHono\Hono;
use Psr\Http\Message\ResponseInterface;

$app = new Hono();
$app->use('*', function (Context $c, callable $next): void {
    $c->set('startedAt', hrtime(true));
    $next();
    $c->header('X-App', 'php-hono');
});
$app->get('/', fn (Context $c): ResponseInterface => $c->text('Hono on PHP'));
$app->get('/users/:id', fn (Context $c): ResponseInterface =>
    $c->json(['id' => $c->req->param('id')])
);
$app->onError(fn (Throwable $e, Context $c): ResponseInterface =>
    $c->json(['error' => 'Internal Server Error'], 500)
);
$response = $app->request('/users/42');
```

| API | Контракт MVP |
| --- | --- |
| `get/post/put/patch/delete/options/query(string $path, callable ...$handlers): static` | Явный path; `query` здесь — HTTP-метод QUERY, а не чтение query string |
| `on(string\|array $methods, string\|array $paths, callable ...$handlers): static` | Любые валидные HTTP method tokens, нормализация в uppercase |
| `all(string $path, callable ...$handlers): static` | Любой метод в общей цепочке |
| `use(string $path, callable ...$middleware): static` | Для глобального middleware явно передавать `'*'` |
| `route(string $prefix, Hono $child): static` | Импорт существующих маршрутов child |
| `basePath(string $prefix): Hono` | Представление общей таблицы с другим префиксом |
| `notFound(callable $handler): static` | Callback `(Context): ResponseInterface` |
| `onError(callable $handler): static` | Callback `(Throwable, Context): ResponseInterface`, контракт Hono v4 |
| `handle(ServerRequestInterface $request): ResponseInterface` | PSR-15 RequestHandlerInterface; не отправляет HTTP-ответ сам |
| `fetch(ServerRequestInterface $request, array $env = []): ResponseInterface` | Hono-style вход с bindings; синхронный |
| `request(string\|ServerRequestInterface $input, array $options = [], array $env = []): ResponseInterface` | Вызов в памяти; options: method, headers, body; никакой сети |

Для `handle()` env берётся из конфигурации приложения; `fetch/request` используют явно переданный env только в текущем запросе. Аргументы и заголовки не меняют конфигурацию приложения.

Для готовых сторонних middleware в MVP предусмотрен `PhpHono\Middleware\Psr15::wrap(MiddlewareInterface $middleware): callable`. Результат передаётся в `use()` или цепочку handlers маршрута наряду с обычными callable. Подключаемый пакет остаётся неизменным; пользователь не переписывает его `process()` под Context.

## C-01. Routing и жизненный цикл

- Поддержать `/`, точные пути, `:id`, необязательный последний сегмент `:id?`, глобальные `*`/`/*`, завершающий `/prefix/*`, regex сегмента `:id{[0-9]+}`.
- `/prefix/*` совпадает с `/prefix`, `/prefix/`, `/prefix/a`, но не с `/prefix2`. Path case-sensitive. Query string не участвует в matching.
- Для regex разрешён общий профиль JavaScript/PCRE: литералы, классы символов, квантификаторы и non-capturing alternation внутри одного сегмента. Запретить backreferences, lookaround, именованные/capturing groups и переход через `/`; некорректный/неподдерживаемый шаблон отклонять при регистрации. Точный набор фиксируется таблицей fixtures в T02/T07.
- Path имеет configurable предел 8192 bytes по умолчанию; превышение даёт 414. Для PCRE задать конечные per-pattern match/depth limits без изменения глобальных настроек процесса; resource/backtracking errors — ошибка исполнения через onError, а не отсутствие match/404. Конкретные значения и adversarial cases закрепить в T02.
- `*` внутри пути, suffix wildcard `/assets*`, несколько optional segments и regex через несколько сегментов не поддерживаются до отдельного расширения. Не трактовать их молча как другой маршрут.
- Все совпавшие handlers идут в порядке регистрации; параметры хранятся на каждом совпадении. При повторе имени в пути сохраняется первое значение. Параметры endpoint не появляются заранее у глобального middleware.
- После `$next()` восстанавливаются параметры вызывающего handler; upstream Hono оставляет параметры последнего downstream handler. Это намеренное отличие, сохранённое в manifest и differential fixture.
- `strict=true` по умолчанию различает `/a` и `/a/`; `strict=false` удаляет один последний `/` входного пути, кроме корня. Не добавлять redirect автоматически.
- В PHP `req->path()` сохраняет исходный encoded URI path и при `strict=false`; нормализация относится только к matching. Отличие от upstream отмечено в manifest и PHP-тесте.
- Регистрация замораживается при первом dispatch; позднее добавление маршрута или middleware, включая изменение через basePath view, даёт явную ошибку конфигурации.
- `route()` импортирует snapshot child routes; child должен быть сконфигурирован заранее. Импортировать дочерний error handler, использовать корневой notFound. `basePath()` делит таблицу, но не изменяет префикс исходного экземпляра.

## C-02. Middleware и финализация

- Handler получает Context и доступный `$next`; допустимый результат — Response или отсутствие результата (`void/null`). Асинхронного контракта нет.
- Порядок: `A-before → B-before → endpoint → B-after → A-after`. Без `$next()` downstream не вызывается. Повторный `$next()` в одном middleware — ошибка.
- Возвращённый Response устанавливается, если ответ ещё не финализирован. Фабрики `text/json/html/body` сами по себе ответ не финализируют: его нужно вернуть либо явно установить.
- `$c->setResponse($response)` явно заменяет текущий ответ. После `$next()` это способ замены; простое `return $otherResponse` уже финализированный ответ не заменяет. `$c->header()` после `$next()` обновляет текущий PSR-7 response.
- Достижение конца цепочки без ответа вызывает notFound. Остановка handler без ответа и без `$next()` — ошибка разработчика и 500 через onError, независимо от числа handlers.
- Исключения downstream преобразуются в Response до возврата из `$next()`, поэтому upstream middleware может добавить headers к ошибке. Ошибка после `$next()` также идёт в onError.
- PSR-15 bridge поддерживает request/response middleware, ранний ответ, передачу attributes/headers/body и точную установку возвращённого PSR Response без восстановления удалённых headers. Внутри route pipeline он не меняет уже выбранный маршрут; URI/method rewrite и повторное выполнение downstream не поддерживаются. Ошибки endpoint уже преобразованы onError, поэтому сторонний catch middleware получает готовый error response, а не исходный Throwable. Подробности и приёмочные интеграции — в T21 и архитектуре.

## C-03. Request

- `raw(): ServerRequestInterface`, `method(): string`, `path(): string`, `url(): string`; `$c->req` — request-scoped оболочка.
- `param(?string $name = null)`, `query(?string $name = null)`, `queries(?string $name = null)`, `header(?string $name = null)` возвращают значение либо карту; отсутствующее одиночное значение — `null`.
- `query('x')` — первое значение; `queries('x')` — список всех. `?x=` даёт пустую строку, отсутствие x — null. Сохранить имена с точкой, пробелом и `[]`, учитывать `+` как пробел. Не использовать `parse_str` для совместимого query parser.
- Headers case-insensitive; карта от `header()` использует lowercase keys. Многозначные response headers доступны через PSR-7; `Set-Cookie` не склеивается.
- Не декодировать `%2F` в разделитель до matching. Параметры декодировать после matching ровно один раз. Некорректное percent encoding/UTF-8 в пути или query отклонять с 400 — намеренное отличие от tolerant decoding Hono. Проверять raw request target до преобразований адаптера.
- `text(): string`, `json(): mixed`, `parseBody(bool $all = false): array` читают общий кеш тела. Non-seekable stream считывается один раз с ограничением размера. Прямое чтение raw stream пользователем не гарантирует повторяемость; рекомендуемый путь — методы оболочки.
- `json()` декодирует объекты в `stdClass`, массивы в array; ошибки JSON — 400. Целые вне диапазона PHP integer сохранять как строки (`JSON_BIGINT_AS_STRING`); для differential JSON-сценариев использовать безопасный общий числовой диапазон. Кодирование невалидных значений в `c.json()` вызывает ошибку, не молча пустой ответ.
- Для urlencoded `parseBody()` берёт последнее повторяющееся поле; при `all=true` повторения становятся массивом, одиночное значение остаётся скаляром. Имя `foo[]` сохраняется и всегда даёт массив. `dot:true` не поддерживать.
- Для multipart MVP использовать уже разобранные PSR-7 parsed body/uploaded files от SAPI/адаптера. `UploadedFileInterface` доступен через `uploadedFiles()`. PHP уже нормализует имена и повторения, поэтому raw multipart parity не заявляется; `all=true` для multipart отклоняется явно. Успешный разбор, включая пустую форму, обозначается transport attribute `php_hono.multipart_parsed=true`; одних пустых массивов недостаточно. Без marker возвращается 415. Не писать собственный multipart parser в MVP.
- В тестах multipart передавать подготовленный PSR request с marker. Адаптер устанавливает marker только для поддержанного SAPI parsing path после проверки доступных length/upload ошибок. Превышение `post_max_size` при известном размере и upload errors выявляются явно; без raw bytes/достоверного размера PHP не всегда позволяет отличить потерянное тело от пустого. Для этой границы обязателен лимит web server/PHP; полное обнаружение всех SAPI ошибок не обещается.

## C-04. Context и Response

- Новый Context на каждый запрос: req, env, переменные `set/get`, текущий Response, error, prepared headers/status. Не хранить его в static/global.
- `set(string $key, mixed $value): void`, `get(string $key): mixed`; отсутствующий ключ — null. Для различения отсутствующего и явно null предусмотрен `has(string $key): bool`.
- `extension(string $type): object` — типизированное чтение уже установленного объекта из того же хранилища variables. PHPDoc: `@template T of object`, `@param class-string<T> $type`, `@return T`. Запись: `set(AuthContext::class, $object)`; для интерфейса — явно `set(AuthState::class, $implementation)`. Ключ сравнивается точно по строке, без нормализации регистра и поиска по совместимым типам; значение должно удовлетворять `instanceof $type`.
- Отсутствующий ключ, null, скаляр или объект другого типа в `extension()` вызывают `\LogicException`; внутри pipeline ошибка проходит через onError, а из самого onError — через существующий fallback 500. Вне dispatch исключение получает вызывающий код. `get/has` сохраняют прежнее поведение. `extension()` не создаёт и не клонирует объект, не выполняет autowiring, не кеширует результат отдельно: последующий `set()` под тем же ключом виден при следующем чтении. Уже полученные ссылки на прежний объект не изменяются.
- Расширение Context выполняется композицией: приложение или middleware устанавливает собственные объекты состояния до использующего их handler. Объекты пользователя/tenant и другие mutable request data создаются заново на каждый запрос; запись одного общего объекта в разные Context сама по себе не даёт изоляции. Разные расширения используют собственные class/interface keys; framework не требует общего подкласса Context.
- `text/json/html/body($value, ?int $status = null, array $headers = [])` создают ResponseInterface; null использует prepared status, а при его отсутствии 200. Также доступны `redirect(string $url, int $status = 302)`, `notFound()`, `status(int $code)`, `header(string $name, string\|array\|null $value, bool $append = false)`, `response(): ?ResponseInterface`, `setResponse(ResponseInterface): void`.
- `status()` задаёт статус по умолчанию только для следующих фабрик; явно переданный статус фабрики имеет приоритет. `header(..., null)` удаляет header, append добавляет значение. Для изменения статуса готового ответа использовать PSR `withStatus()` и `setResponse()`.
- При первой установке Response prepared headers применяются один раз: для обычных headers явно заданное значение Response имеет приоритет, а Set-Cookie объединяется в порядке prepared → Response без склеивания строк. Затем prepared queue очищается. После финализации `header()` меняет текущий Response, включая удаление; старый prepared header не должен воскресать.
- При `setResponse()` отсутствующие в новом ответе headers наследуются из текущего, кроме Content-Type, Content-Length, Content-Encoding, Transfer-Encoding, ETag и Last-Modified: они относятся к заменённому телу. Явные новые headers имеют приоритет. Для Set-Cookie явный список нового ответа заменяет весь старый список, при отсутствии наследуется старый; повторное setResponse одного ответа ничего не дублирует. Это выбранная PHP-политика с отдельными difference fixtures, а не побайтовая копия setter Hono.
- Content-Type: text — `text/plain; charset=UTF-8`, html — `text/html; charset=UTF-8`, json — `application/json`. `html()` принимает готовую строку и не экранирует её автоматически; отдельный `escapeHtml()` предназначен для вставляемых значений.
- Ответы 204/205/304 не содержат body. Заголовки и URI проходят проверку на недопустимые control characters; не полагаться на `header()` PHP как на валидатор.

Зависимости handlers и middleware передаются через конструкторы или явно захваченные сервисы. Регистрация принимает готовые callable objects. Приложение может собрать их через внешний PSR-11 container в bootstrap; Context не предоставляет container/service locator. Автоматический resolver строковых имён классов, autowiring, `contextFactory/stateFactory` и динамические методы Context не входят в MVP. Прикладные request-scoped factories вызываются внутри обработки запроса; их cleanup имеет явного владельца.

PSR-15 attributes и Context variables — отдельные хранилища. Прикладной mapper, зарегистрированный после соответствующего PSR middleware, читает текущий `$c->req->raw()->getAttribute(...)`, проверяет тип и явно устанавливает extension. При отсутствии/удалении атрибута он сначала очищает прежнее значение через `set($type, null)`, затем продолжает обработку либо завершает запрос согласно политике приложения; ранний ответ не должен оставлять прежний AuthContext доступным upstream. Автоматического копирования в любую сторону нет. При восстановлении upstream raw request после downstream Context variables этого запроса не откатываются; mapper должен явно выбрать источник истины. Данные, нужные стороннему PSR middleware, передаются в PSR attributes на соответствующей стороне границы, а не через скрытую синхронизацию Context.

## C-05. Ошибки, 404, HEAD

- Неизвестный маршрут и несовпавший метод дают 404 с `404 Not Found`. Автоматические 405/OPTIONS не включены; OPTIONS регистрируется явно либо обрабатывается CORS.
- HEAD выполняет GET chain, сохраняет `req.method() === 'HEAD'`, а после обработки убирает body, сохраняя статус и допустимые headers. GET Content-Length, если задан, не заменять на ноль. Отдельный `on('HEAD', ...)` не используется — это совместимость с выбранным Hono v4.
- `HttpException` хранит status, message, optional Response и previous Throwable. Статус исключения имеет приоритет над вложенным Response. CORS/security headers контекста не должны теряться на HTTP-ошибке.
- `onError` получает Throwable, устанавливает error в Context и возвращает Response. По умолчанию известная HttpException даёт свой ответ, прочие исключения — 500 `Internal Server Error`; stack trace не отправляется клиенту.
- Если пользовательский onError сам выбрасывает исключение, срабатывает один встроенный fallback 500 без рекурсии. Это явно заданное PHP-правило, не обещание воспроизвести все edge cases upstream.

## C-06. Validation и базовые middleware

- Validator принимает target (`json`, `form`, `query`, `param`, `header`, `cookie`) и callback. Callback возвращает нормализованные данные либо Response для раннего завершения. `req->valid($target)` читает только успешно сохранённые данные; отсутствие validation — ошибка разработчика. Свою schema language не создавать.
- Body limit проверяет заявленный Content-Length и до `$next()` читает raw body в ограниченный кеш, останавливаясь не позднее limit+1 bytes. Слишком большое тело отклоняется независимо от того, читает ли его endpoint; downstream использует тот же кеш. Для SAPI multipart чтение уже выполнено PHP до framework: доступны только transport metadata/известные ошибки, ограничения web server/PHP обязательны для этой границы.
- CORS: normal/preflight, allowed origins/methods/headers, credentials, expose/max-age и Vary. Нельзя сочетать wildcard origin с credentials; отклонять такую конфигурацию. Origin по умолчанию не отражать без проверки.
- Cookies: get/set/delete, атрибуты Path/Domain/Expires/Max-Age/Secure/HttpOnly/SameSite, отдельные Set-Cookie. Подписанные/encrypted cookies за пределами MVP. `SameSite=None` требует Secure.
- Secure headers: фиксированный документированный набор значений; CSP только из явной конфигурации. Request ID: валидировать входное значение/длину, иначе генерировать через `random_bytes`; logger пишет метод, путь, статус, длительность, id, без request body, Authorization и Cookie.

## C-07. Runtime и изоляция

- Ядро не читает superglobals и не вызывает `echo`, `header()`, `exit()`. SAPI adapter строит ServerRequest, вызывает приложение и отправляет Response.
- Proxy headers не доверяются автоматически. Scheme/host/IP берутся из transport config; доверенные proxy настраиваются явно на уровне adapter/server.
- Отправлять несколько Set-Cookie отдельными строками. HEAD и bodyless statuses не отправляют bytes. Закрытие/rewind streams имеет явного владельца.
- Повторные вызовы одного App не переносят Context, extensions/variables, env, validation, error, cookies или body cache. Routes после freeze разделяются безопасно; mutable state пользовательских closures и сервисов — ответственность приложения и отдельно описывается. Общий handler не сохраняет текущий Context. Пользователь, tenant, транзакция и Unit of Work имеют lifecycle запроса; безопасные общие сервисы могут жить вместе с App. Освобождение ресурсов и rollback задаются явно, в том числе при short-circuit и обработанной onError ошибке: возврат из `$next()` сам по себе не означает успех операции.
- В 1.0 streaming producer запускается emitter после headers. Ошибку после начала отправки нельзя превратить в новый 500: её логируют, поток закрывают. Отключение клиента прекращает producer по возможности runtime; блокирующий I/O не получает обещания мгновенной отмены.

## Явные различия и исключения

Все различия перечисляются в `docs/ru/compatibility.md` и имеют собственные PHP-tests: PSR вместо Fetch; sync вместо Promise; обязательные paths; ограниченная route grammar и path length; 400 для malformed URI; единый 500 для забывшего Response handler; правила headers при замене Response; PHP JSON types/числа; SAPI multipart normalization; конечный body buffer и проверка фактической длины даже при наличии Content-Length; отсутствие browser/edge bindings и TypeScript inference. Integer-like ключи PHP array могут приводиться к int — точное JS object key typing не обещается. JSX/WebSocket намеренно отсутствуют по требованию пользователя.

Неподдерживаемые параметры API должны отклоняться явно. Не добавлять заглушки, которые возвращают успешный результат без выполнения обещанной функции.

Типизированный `Context::extension()` — PHP-specific дополнение к `set/get`, не перенос TypeScript generic API. Для него нужны собственные C-04 PHP contract tests; JS upstream harness не должен имитировать отсутствующий метод. Использование внешнего DI-контейнера не означает совместимость lifecycle любого пакета с worker.

## Приёмка

MVP считается готовым, когда все поддерживаемые C-01–C-07 сценарии проходят unit/contract tests, differences имеют отдельные тесты, минимальное приложение работает через `request()` и реальный SAPI/FPM, а воспроизводимый пример устанавливается через Composer. Обязательна T21: реальные сторонние PSR-15 пакеты работают через bridge на PHP 8.5 без изменения их кода. Для 1.0 дополнительно обязательны задачи T22–T32 и отдельные streaming/worker integration tests. Процент совместимости без объявленного набора сценариев не использовать.
