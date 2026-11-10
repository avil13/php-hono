# Middleware

[English](../en/middleware.md) | **Русский**

Middleware php-hono 1.0 для PHP 8.5+. [Оглавление документации](README.md).

Middleware выполняет общую обработку запроса: может проверить данные, передать управление следующему обработчику, изменить ответ или завершить запрос раньше.

## Код до и после обработчика

```php
use PhpHono\Context;
use PhpHono\Hono;
use Psr\Http\Message\ResponseInterface;

$app = new Hono();
$app->use('*', function (Context $c, callable $next): void {
    $startedAt = hrtime(true);

    $next();

    $milliseconds = (hrtime(true) - $startedAt) / 1_000_000;
    $c->header('X-Response-Time', sprintf('%.3fms', $milliseconds));
});

$app->get('/hello', fn (Context $c): ResponseInterface => $c->text('Hello'));
```

`$next()` синхронно запускает оставшуюся цепочку. После его возврата ответ доступен через `$c->response()`. `async`/`await` здесь нет. Далее `Context`, `ResponseInterface` и `$app` означают импорты и экземпляр из этого примера.

Для middleware A, затем B, затем endpoint порядок такой:

```text
A: до next
  B: до next
    endpoint
  B: после next
A: после next
```

Зарегистрируйте внешние middleware раньше вложенных. Например, измерение времени, установленное первым, охватывает всю оставшуюся цепочку.

## Область действия

```php
$app->use('/api/*', function (Context $c, callable $next): void {
    $c->header('X-API-Version', '1');
    $next();
});

$app->get('/api/status', fn (Context $c): ResponseInterface =>
    $c->json(['ok' => true])
);
```

`use('*', ...)` действует на все пути, а `use('/api/*', ...)` — на `/api` и вложенные пути. Middleware участвуют в общем порядке регистрации с маршрутами. Если предыдущий обработчик уже вернул ответ без `$next()`, более поздний middleware не запустится.

Общие middleware, которые должны менять также 404 и ответы ошибок, размещайте до маршрутов. Правила шаблонов и группировки — в [маршрутизации](routing.md).

## Ранний ответ

Middleware может вернуть ответ и не вызывать `$next()`:

```php
$requireClientVersion = function (Context $c, callable $next): ?ResponseInterface {
    if ($c->req->header('X-Client-Version') === null) {
        return $c->json(['error' => 'X-Client-Version is required'], 400);
    }

    $next();
    return null;
};

$app->post('/events', $requireClientVersion,
    fn (Context $c): ResponseInterface => $c->json(['accepted' => true], 202)
);
```

Без заголовка endpoint не выполняется. Это пример проверки входного требования, не механизм аутентификации. Несколько handlers одного маршрута работают как последовательность middleware и конечного обработчика.

## Передача данных через Context

Используйте `set/get/has` для данных текущего запроса:

```php
$app->use('/api/*', function (Context $c, callable $next): void {
    $c->set('locale', $c->req->query('lang') ?? 'ru');
    $next();
});

$app->get('/api/preferences', fn (Context $c): ResponseInterface =>
    $c->json(['locale' => $c->get('locale')])
);
```

Для каждого запроса создаётся новый Context. Не сохраняйте текущего пользователя или другие request data в static-полях и общих mutable closures: в постоянном worker-процессе они переживут запрос. `has()` отличает отсутствующий ключ от ключа со значением `null`.

Для типизированного состояния middleware записывает `set(AuthContext::class, $auth)`, а следующий handler читает `extension(AuthContext::class)`. Ключи точные, объект должен иметь запрошенный тип; метод не запускает factory/autowiring. Создавайте request state внутри middleware, а зависимости самого callable передавайте конструктором. [Руководство по Context extensions и DI](context-extensions.md).

## Изменение ответа после next

Для заголовков используйте `$c->header()`. Чтобы заменить тело или статус, установите новый ответ явно:

```php
$app->use('/legacy', function (Context $c, callable $next): void {
    $next();

    $response = $c->response();
    if ($response !== null && $response->getStatusCode() === 404) {
        $c->setResponse($c->json(['error' => 'Legacy resource not found'], 404));
    }
});
```

После `$next()` простой `return $c->json(...)` уже финализированный ответ не заменяет. Для этого нужен `setResponse()`. Сам вызов `text/json/html/body` только создаёт Response; до финализации его необходимо вернуть или явно установить.

При замене действуют правила наследования headers: служебные headers прежнего тела не переносятся, а остальные отсутствующие headers могут наследоваться. Для удаления заголовка используйте `$c->header('Header-Name', null)`. Полные правила, включая Set-Cookie, — в [Context и ответах](context-response.md).

## Ошибки middleware

Downstream exception обрабатывается через `onError` до возврата из `$next()`. Код внешнего middleware после `$next()` поэтому может добавить заголовки к ответу ошибки. Исключение, выброшенное самим middleware после `$next()`, тоже попадает в `onError`.

Поэтому возврат из `$next()` не является подтверждением успешной бизнес-операции. Не делайте безусловный commit после него; завершайте транзакцию по явному результату сервиса, а cleanup/rollback незавершённой работы выполняйте в `finally`. [Пример lifecycle](context-extensions.md#lifecycle-и-освобождение-ресурсов).

Есть два недопустимых сценария:

- Повторный `$next()` из одного middleware: оставшуюся цепочку нельзя запустить дважды.
- Завершение без `$next()`, возвращённого Response и `setResponse()`: это ошибка разработчика, которая превращается в 500.

Не забывайте `return` перед фабрикой ответа. `void` подходит middleware, который вызывает `$next()` и меняет Context. Если middleware может вернуть ранний Response, укажите подходящий return type, например `?ResponseInterface`, и верните `null` в ветке продолжения, как выше.

## Встроенные middleware

Middleware — готовые invokable-объекты из `PhpHono\Middleware`. Их можно передать в `use()` или в список handlers маршрута.

```php
use PhpHono\Middleware\BodyLimit;
use PhpHono\Middleware\Cors;
use PhpHono\Middleware\SecureHeaders;

$app->use('*', new SecureHeaders());
$app->use('/api/*', new Cors(origin: 'https://app.example.com'));
$app->use('/api/*', new BodyLimit(maxBytes: 1_048_576));
```

Поставьте эти вызовы до регистрации endpoints. CORS должен быть внешним по отношению к auth/CSRF, если preflight должен завершаться до проверки credentials.

| Возможность | Реализованный API |
| --- | --- |
| Validator | `new Validator($target, $validate)`; результат через `req->valid()` |
| Body limit | `new BodyLimit($maxBytes)` до чтения тела |
| CORS, secure headers | `new Cors(...)`, `new SecureHeaders(...)` |
| Request ID, logger | `new RequestId(...)`, `new Logger(...)` |
| Cookies | `PhpHono\Helper\Cookie`; helper без обязательного middleware |
| Basic/Bearer auth | `BasicAuth::credentials(...)`, `BearerAuth::token(...)` или callback-конструкторы |
| CSRF | `new Csrf(origins: [...])` |
| ETag и gzip | `new ETag()`, затем `new Compress()` |
| 405 и slash policy | `new MethodNotAllowed($app)`, `new TrailingSlash(...)` |
| Static files | `new StaticFiles($root, $prefix)` |

Сигнатуры, defaults и примеры базовых возможностей находятся в [руководстве по встроенным middleware](builtin-middleware.md), auth/CSRF/ETag/gzip/files — в [HTTP middleware 1.0](advanced-middleware.md).

Для multipart часть обработки уже выполнена PHP до middleware. Поэтому body limit фреймворка не заменяет `post_max_size`, upload limits и лимиты web server. Детали — в [Request](request.md) и [запуске](deployment.md).

## PSR-15 middleware

**Готовые сторонние PSR-15 middleware поддерживаются через bridge.** API: `PhpHono\Middleware\Psr15::wrap(MiddlewareInterface $middleware): callable`. Он позволяет использовать исходный объект из Composer-пакета без переписывания `process()` под Context.

Например, [middlewares/response-time](https://github.com/middlewares/response-time) добавляет время обработки в response header. После установки Composer-пакета подключите его объект:

```php
use Middlewares\ResponseTime;
use PhpHono\Context;
use PhpHono\Hono;
use PhpHono\Middleware\Psr15;

$app = new Hono();
$app->use('*', Psr15::wrap(new ResponseTime()));
$app->get('/health', fn (Context $c) => $c->json(['ok' => true]));
```

Для одного маршрута результат `wrap()` можно передать среди handlers: `$app->get('/health', Psr15::wrap($middleware), $handler)`. Hono callable и PSR middleware работают в общем порядке регистрации.

Bridge передаёт изменённые request headers/body/attributes downstream, допускает ранний Response и точно сохраняет возвращённый PSR response, включая удаление headers/Set-Cookie. Доступный raw body передаётся в повторно читаемом виде из общего ограниченного кеша; это работает и после предыдущего Hono reader, но может потребовать буферизации. Лимит тела приложения сохраняется.

PSR attributes не превращаются в Context extensions автоматически. Разместите mapper после PSR middleware: он читает текущий `$c->req->raw()` и явно записывает объект через `set(Type::class, $object)`. При восстановлении upstream request view variables Context не откатываются; они общие для текущего запроса. [Пример mapper](context-extensions.md#psr-15-attributes-и-typed-extension).

Границы этого профиля:

- Внутри bridge маршрут уже выбран: URI или HTTP-метод менять нельзя. Для таких middleware используйте внешний PSR-15 pipeline **перед** App. Одиночный объект стандартно вызывает `$middleware->process($request, $app)`; собственный dispatcher php-hono для этого не требуется.
- Downstream можно вызвать только один раз. Retry middleware с повторным `handle()` не поддерживается этим bridge.
- Исключения endpoint уже преобразованы Hono `onError` в Response. Сторонний exception handler не заменяет этот механизм; внешний pipeline сам обрабатывает ошибки своих компонентов вне App.
- Middleware Laravel/Symfony с собственными интерфейсами и старый double-pass API не становятся PSR-15-совместимыми автоматически.

В `composer.lock` закреплены тестовые версии [middlewares/uuid](https://github.com/middlewares/uuid) 2.1.0 и [middlewares/response-time](https://github.com/middlewares/response-time) 2.1.0. Интеграционные тесты используют их реальные классы; отдельные contract tests проверяют body, attributes, ошибки и смешанный порядок. Эти пакеты не нужны ядру и не добавляются в production runtime автоматически.

Далее: [Request](request.md), [обработка ошибок](errors.md), [тестирование](testing.md).
