# Встроенные middleware и cookies

[English](../en/builtin-middleware.md) | **Русский**

Реализованный API php-hono 1.0 для PHP 8.5. Все middleware — callable-объекты: регистрируйте их через `$app->use($path, $middleware)` или перед endpoint в `$app->get/post(...)`. Порядок регистрации определяет порядок выполнения. Примеры секций — отдельные варианты настройки: middleware регистрируется до соответствующих endpoint и первого dispatch. Общий pipeline и сторонние PSR-15 middleware описаны в [middleware](middleware.md).

```php
use PhpHono\Context;
use PhpHono\Hono;
use PhpHono\Middleware\BodyLimit;
use PhpHono\Middleware\Cors;
use PhpHono\Middleware\RequestId;
use PhpHono\Middleware\SecureHeaders;
use Psr\Http\Message\ResponseInterface;

$app = new Hono();
$app->use('*', new RequestId(), new SecureHeaders());
$app->use('/api/*', new Cors(origin: 'https://client.example'));
$app->use('/api/*', new BodyLimit(maxBytes: 256 * 1024));
$app->post('/api/echo', fn(Context $c): ResponseInterface => $c->text($c->req->text()));
```

## Validator

```text
new PhpHono\Middleware\Validator(string $target, callable $validate)
```

Targets: `json`, `form`, `query`, `param`, `header`, `cookie`. Другой target отклоняется конструктором с `InvalidArgumentException`. Callback получает `(mixed $input, Context $c)` и возвращает преобразованные данные либо `ResponseInterface` для раннего ответа. Возвращаемое значение, включая `null` и `false`, записывается в `$c->req->valid($target)`; на следующем запросе оно не сохраняется. Schema library не требуется.

```php
use PhpHono\Middleware\Validator;

$app->post('/api/greetings', new Validator('json', static function (mixed $input, Context $c): mixed {
    if (!$input instanceof stdClass || !property_exists($input, 'name') || !is_string($input->name)) {
        return $c->json(['error' => 'name должен быть строкой'], 422);
    }
    return ['name' => trim($input->name)];
}), static fn(Context $c): ResponseInterface => $c->json($c->req->valid('json')));
```

| Target | Вход callback |
| --- | --- |
| `json` | Результат `req->json()` для `application/json` и `application/*+json`; иначе `[]` |
| `form` | URL-encoded поля со сбором повторов; multipart — подготовленные SAPI поля |
| `query` | Одиночное значение — строка, повторяющееся — список строк; имена сохраняются |
| `param` | Параметры текущего совпавшего маршрута |
| `header` | Карта строковых значений с именами в нижнем регистре |
| `cookie` | Результат `Cookie::get($c)` |

Malformed JSON/form encoding остаётся ошибкой 400, превышение лимита — 413; callback в этом случае не вызывается. `valid()` без успешно сохранённого target выбрасывает `LogicException`. Для параметров ставьте validator на маршрут `/:id`, а не в глобальное `use('*', ...)`, у которого нет параметров endpoint. Реализация: [Validator.php](https://github.com/avil13/php-hono/blob/master/src/Middleware/Validator.php).

## BodyLimit

```text
new PhpHono\Middleware\BodyLimit(int $maxBytes, ?callable $onError = null)
```

Предел неотрицательный, измеряется в байтах; ноль разрешает только пустое тело. Middleware проверяет `Content-Length`, затем до `$next()` читает фактическое тело ограниченным reader. Отсутствующая или ложная меньшая длина не обходит проверку. Чтение останавливается не позже limit + 1 байта. При `Transfer-Encoding` объявленная длина не используется как доверенный размер, фактическая проверка сохраняется.

Предел может только уменьшить `Hono(maxBodySize: ...)`, по умолчанию 8 MiB. Кешированные байты проверяются и при дальнейшем уменьшении лимита. Endpoint повторно читает этот же кеш без потери данных. Регистрируйте BodyLimit до parsers и bridge, если лимит маршрута должен сработать до их буферизации.

По умолчанию превышение даёт 413. Callback `(Context $c): ResponseInterface` позволяет задать тело ошибки:

```php
$app->use('/upload', new BodyLimit(1_048_576,
    static fn(Context $c): ResponseInterface => $c->json(['error' => 'Файл слишком большой'], 413),
));
```

Некорректный `Content-Length` даёт 400 и не вызывает этот callback. Callback применяется только к проверке BodyLimit, а не к ошибкам endpoint. SAPI multipart уже разобран PHP: `post_max_size`, `upload_max_filesize` и ограничения HTTP-сервера действуют раньше framework. Подробнее: [чтение запроса](request.md). Реализация: [BodyLimit.php](https://github.com/avil13/php-hono/blob/master/src/Middleware/BodyLimit.php).

## Cors

```php
new PhpHono\Middleware\Cors(
    origin: '*',
    allowMethods: ['GET', 'HEAD', 'PUT', 'POST', 'DELETE', 'PATCH', 'QUERY'],
    allowHeaders: [],
    credentials: false,
    exposeHeaders: [],
    maxAge: null,
);
```

`origin` принимает точную строку origin, список разрешённых origins либо callback `(string $origin, Context $c): ?string`. Callback возвращает разрешённый origin или `null` для отказа. Пустая строка тоже означает отказ. Список с `'*'` разрешает любой origin. Без входного `Origin` разрешающие headers не добавляются.

`credentials: true` несовместим с wildcard: для строковой/списковой конфигурации конструктор сразу выбрасывает `InvalidArgumentException`; результат `'*'` из callback проверяется при запросе. Для credentials используйте точный allowlist. `maxAge` — неотрицательное число секунд либо `null`, чтобы не добавлять заголовок.

Preflight — `OPTIONS` с непустыми `Origin` и `Access-Control-Request-Method`. Он завершается ответом 204 до endpoint, без тела, `Content-Type` и `Content-Length`. Обычный `OPTIONS` проходит дальше. При `allowHeaders: []` отражаются проверенные HTTP token names из `Access-Control-Request-Headers`, а `Vary` включает это имя. Некорректные имена запрошенных headers дают 400. Для фиксированного разрешённого набора передайте список `allowHeaders`.

Для динамического/точного origin добавляется `Vary: Origin`; существующие значения объединяются без повторов, `Vary: *` сохраняется. Headers применяются после `$next()`, поэтому действуют и на downstream 404/500. Middleware заменяет собственные `Access-Control-*` headers: endpoint не может обойти allowlist, вернув `Access-Control-Allow-Origin: *`. `Set-Cookie` и другие headers сохраняются. Отказ origin сам по себе не заменяет ответ на 403 — браузер не получает разрешение прочитать его.

CORS не заменяет аутентификацию или CSRF-проверку. Реализация: [Cors.php](https://github.com/avil13/php-hono/blob/master/src/Middleware/Cors.php).

## SecureHeaders

```text
new PhpHono\Middleware\SecureHeaders(array $overrides = [], bool $removePoweredBy = true)
```

После endpoint или обработанной downstream ошибки middleware устанавливает следующие значения:

| Header | Значение |
| --- | --- |
| `Cross-Origin-Resource-Policy` | `same-origin` |
| `Cross-Origin-Opener-Policy` | `same-origin` |
| `Origin-Agent-Cluster` | `?1` |
| `Referrer-Policy` | `no-referrer` |
| `Strict-Transport-Security` | `max-age=15552000; includeSubDomains` |
| `X-Content-Type-Options` | `nosniff` |
| `X-DNS-Prefetch-Control` | `off` |
| `X-Download-Options` | `noopen` |
| `X-Frame-Options` | `SAMEORIGIN` |
| `X-Permitted-Cross-Domain-Policies` | `none` |
| `X-XSS-Protection` | `0` |

Ключи `$overrides` сравниваются без учёта регистра; строка задаёт значение, `null` отключает установку этого header middleware. Отключение не удаляет значение, установленное самим endpoint. `X-Powered-By` удаляется по умолчанию. CSP и COEP автоматически не добавляются; CSP задаётся явно:

```php
$app->use('*', new SecureHeaders([
    'Content-Security-Policy' => "default-src 'self'; object-src 'none'",
    'X-Frame-Options' => null,
]));
```

Подберите CORP/COOP/CSP и HSTS к ресурсам и HTTPS-политике своего приложения. Реализация: [SecureHeaders.php](https://github.com/avil13/php-hono/blob/master/src/Middleware/SecureHeaders.php).

## RequestId

```text
new PhpHono\Middleware\RequestId(string $headerName = 'X-Request-Id', string $contextKey = 'requestId')
```

Принимается входное значение длиной 1–255 символов из `A-Z a-z 0-9 _ = -`. Иначе генерируется новый случайный 128-битный идентификатор: 32 шестнадцатеричных символа через `random_bytes(16)`. Это не UUID. Значение доступно в `$c->get('requestId')` и response header; middleware повторно устанавливает header после `$next()`, включая обработанную ошибку.

Настройка имени header и context key явная. Request ID помогает корреляции, но не подтверждает личность пользователя: допустимый входной ID клиент может выбрать сам. Реализация: [RequestId.php](https://github.com/avil13/php-hono/blob/master/src/Middleware/RequestId.php).

## Logger

```text
new PhpHono\Middleware\Logger(callable $sink, ?callable $onFailure = null)
```

Обязательный `$sink` получает массив `{method, path, status, durationMs, requestId}`. Время измеряется монотонными часами. В записи нет query string, тела, cookies или Authorization. Логирование выполняется один раз при выходе из middleware, включая 404/500. `requestId` читается из Context по ключу `requestId`; при отсутствии строки это `null`.

```php
use PhpHono\Middleware\Logger;

$app->use('*', new RequestId(), new Logger(
    static function (array $entry): void {
        error_log(json_encode($entry, JSON_THROW_ON_ERROR));
    },
    static function (Throwable $failure): void {
        error_log('Не удалось записать access log');
    },
));
```

В примере I/O выбирает приложение. Framework не пишет в stdout и не выбирает скрытый log sink. Исключение sink не меняет HTTP-ответ: оно передаётся в необязательный `onFailure(Throwable): void`. Без диагностического callback сбой записи отбрасывается. Исключение самого `onFailure` тоже не заменяет ответ. Для production подключите независимую диагностику/метрики отказов sink. Реализация: [Logger.php](https://github.com/avil13/php-hono/blob/master/src/Middleware/Logger.php).

## Cookie

`PhpHono\Helper\Cookie` предоставляет статические helpers:

```text
Cookie::get(Context $c, ?string $name = null): string|array|null;
Cookie::parse(string $header): array;
Cookie::set(Context $c, string $name, string $value, array $options = []): void;
Cookie::delete(Context $c, string $name, array $options = []): void;
Cookie::serialize(string $name, string $value, array $options = []): string;
```

`get($c)` возвращает карту cookies, `get($c, 'name')` — значение либо `null`. Первый одноимённый cookie имеет приоритет. Percent encoding декодируется, `+` остаётся `+`. Неправильная percent-последовательность или невалидный UTF-8 сохраняется в исходном закодированном виде; некорректная пара пропускается.

```php
use PhpHono\Helper\Cookie;

$app->post('/preferences', static function (Context $c): ResponseInterface {
    Cookie::set($c, 'theme', 'dark', [
        'path' => '/',
        'maxAge' => 3600,
        'secure' => true,
        'httpOnly' => true,
        'sameSite' => 'Lax',
    ]);
    return $c->text('Сохранено');
});
```

Опции: `domain: string`, `path: string` (по умолчанию `/`), `expires: DateTimeInterface`, `maxAge: int >= 0`, `secure: bool`, `httpOnly: bool`, `sameSite: 'Strict'|'Lax'|'None'` без учёта регистра. Неизвестные опции, неправильные типы и попытки внедрения attributes отклоняются с `InvalidArgumentException`. Значение кодируется через `rawurlencode()`, Expires форматируется в GMT.

`SameSite=None` и префикс `__Secure-` требуют `secure: true`. `__Host-` дополнительно требует `path: '/'` и отсутствующий `domain`. Helpers добавляют отдельный `Set-Cookie` как до, так и после `$next()`; значения не объединяются запятыми.

Для удаления передайте тот же path/domain, с которым cookie был установлен: `Cookie::delete($c, 'theme', ['path' => '/'])`. Helper отправляет пустое значение, `Max-Age=0` и дату Unix epoch. При защищённом префиксе сохраните `secure: true`. Подпись и шифрование cookies не предоставляются. Реализация: [Cookie.php](https://github.com/avil13/php-hono/blob/master/src/Helper/Cookie.php).

Другие возможности: [middleware pipeline](middleware.md), [запрос](request.md), [Context и ответы](context-response.md), [расширение Context и DI](context-extensions.md).
