# HTTP middleware 1.0

[English](../en/advanced-middleware.md) | **Русский**

[Оглавление](README.md) · [Порядок middleware](middleware.md) · [Базовые middleware](builtin-middleware.md)

Все классы ниже находятся в `PhpHono\Middleware` и подключаются до endpoints через `$app->use($path, $middleware)`. Они также подходят для списка handlers отдельного маршрута. Примеры независимы; `$app` — экземпляр `PhpHono\Hono`.

## Basic и Bearer authentication

```php
use PhpHono\Middleware\BasicAuth;
use PhpHono\Middleware\BearerAuth;

$app->use('/admin/*', BasicAuth::credentials($username, $password, realm: 'Administration'));
$app->use('/api/*', BearerAuth::token($apiToken));
```

Секреты приходят из конфигурации приложения. Basic передаёт credentials в base64, а Bearer передаёт сам token: используйте HTTPS. Helpers сравнивают секреты через `hash_equals()`. Хранение пользователей, хеширование паролей, JWT и выпуск tokens не входят в middleware.

Для проверки через собственный сервис используйте конструкторы:

```php
use PhpHono\Context;
use PhpHono\Middleware\BasicAuth;
use PhpHono\Middleware\BearerAuth;

$app->use('/admin/*', new BasicAuth(
    verify: fn (string $username, string $password, Context $c): bool =>
        $accounts->verifyPassword($username, $password),
    realm: 'Administration',
));

$app->use('/api/*', new BearerAuth(
    verify: fn (string $token, Context $c): bool => $tokens->isValid($token),
));
```

`realm` по умолчанию — `Restricted`. При отсутствии, неверном формате или отказе verifier возвращается 401 с `WWW-Authenticate`; endpoint не запускается. Для Basic принимается один Authorization header, корректный base64 и первая `:` как разделитель username/password. Bearer принимает один header и формат token из букв, цифр, `-._~+/` с необязательными завершающими `=`. Схема Authorization сравнивается без учёта регистра. Исключение verifier обрабатывает общий `onError`; credentials не включаются в стандартный ответ ошибки.

## CSRF

```php
use PhpHono\Middleware\Csrf;

$app->use('/forms/*', new Csrf());
```

По умолчанию разрешаются Origin того же scheme/host/port, что у PSR-7 URI запроса, **или** `Sec-Fetch-Site: same-origin`. Стандартные HTTP/HTTPS ports нормализуются. `X-Forwarded-Host` и `X-Forwarded-Proto` не меняют доверенный origin. При reverse proxy настройте корректный URI в доверенном внешнем адаптере.

Проверка применяется к методам кроме GET/HEAD/OPTIONS и browser form content types: `application/x-www-form-urlencoded`, `multipart/form-data`, `text/plain`, `text/ping`. Отсутствующий Content-Type трактуется как `text/plain`. JSON не относится к этому профилю. При отказе возвращается 403 до endpoint.

```php
$app->use('/forms/*', new Csrf(
    origins: ['https://app.example.com'],
    fetchSites: [],
));
```

Пустой `origins` означает тот же origin, непустой список задаёт разрешённые HTTP(S) origins. `fetchSites` по умолчанию `['same-origin']`; допустимы также `same-site`, `none`, `cross-site`. Достаточно успешной проверки **одного** из двух источников, как в выбранном Hono profile. Поэтому `fetchSites: []` в примере требует совпадения Origin. Добавление `cross-site` существенно расширяет политику. CORS не заменяет CSRF, а middleware не заменяет отдельную CSRF token policy, если она нужна приложению.

## ETag и gzip

```php
use PhpHono\Middleware\ETag;
use PhpHono\Middleware\Compress;

$app->use('/documents/*', new ETag());
$app->use('/documents/*', new Compress(minimumLength: 1024, level: -1));
```

Порядок важен: после endpoint сначала выполняется Compress, затем внешний ETag. Поэтому strong ETag относится к реально выбранным gzip или identity bytes. Это проверяется интеграцией двух middleware. При обратном порядке Compress превращает существующий strong tag в weak tag (`W/`), сохраняя семантическую эквивалентность.

`ETag(weak: false)` использует SHA-256 для конечного, читаемого и seekable body успешного GET. Хеш вычисляется по всему телу порциями; исходная позиция восстанавливается. Существующий ETag сохраняется. SHA-256 — выбор PHP API, совпадение байтов digest с JavaScript Hono не обещано.

Поддерживаются `If-None-Match: *`, список tags и weak comparison для GET/HEAD, включая запятую внутри quoted tag. Совпадение даёт 304 без тела и Content-Type/Content-Length/Content-Encoding/Transfer-Encoding; прочие headers сохраняются. 204/205/206, non-2xx, streaming и nonseekable bodies пропускаются. HEAD не вычитывает тело ради нового hash: условный ответ возможен по заранее заданному ETag.

`Compress` поддерживает gzip при наличии ext-zlib. `minimumLength` — 1024 bytes, `level` — zlib default `-1` (разрешены `-1..9`). Качество `q` и wildcard учитываются; явный `gzip;q=0` имеет приоритет над `*`. Если явно предпочтён identity, он сохраняется. Неподдерживаемая encoding или отсутствие ext-zlib дают identity, когда клиент его допускает. Явный запрет identity при отсутствии применимого gzip даёт пустой 406. Если identity запрещён, небольшое тело также может быть сжато.

Middleware дополняет `Vary: Accept-Encoding`, сохраняет `Vary: *`, выставляет Content-Encoding и длину сжатого тела. Already encoded responses, bodyless statuses, 206, `Cache-Control: no-transform`, streaming и nonseekable bodies не преобразуются. При явном запрете identity невозможность преобразования даёт 406, кроме already encoded и bodyless responses. Обычный HEAD не запускает compression; если identity запрещён и gzip применим, выставляются gzip metadata без чтения тела и без вычисления длины. 304 не запускает обработку тела.

Буфер результата использует `php://temp` с порогом 2 MiB, после которого данные переходят во временный файл. Это конечный ответ, а не потоковая передача клиенту. Обоим middleware неизвестна прикладная чувствительность данных: для ответов, смешивающих secrets и отражённый пользовательский ввод, выбирайте политику compression осознанно.

## Static files

```php
use PhpHono\Middleware\StaticFiles;

$app->use('/assets/*', new StaticFiles(__DIR__ . '/public/assets', prefix: '/assets'));
```

Root должен существовать и быть директорией; требуется ext-fileinfo. Prefix по умолчанию `/`. GET открывает файл как PSR-7 stream, HEAD возвращает те же Content-Type/Content-Length/Last-Modified без открытия body. Отсутствующий файл и неподходящий метод передают управление дальше. Directory listing, index resolution и Range не реализованы; Range header не включает partial response автоматически.

Root ограничивается через `realpath` и проверку границы директории. Traversal segments, encoded separators и backslash отклоняются; NUL также отклоняет входной HTTP слой. Symlink разрешён только внутрь root. После открытия сверяются inode/device и путь; при неудаче создания response stream ресурс закрывается. Успешный ответ передаёт владение ресурсом PSR stream, который нужно закрывать после потребления; стандартный emitter делает это сам.

Root должен содержать только публичные файлы и не быть доступным для записи недоверенным пользователям. Проверки не заменяют файловые permissions или атомарный filesystem sandbox при параллельной враждебной подмене файлов. Для большой раздачи используйте web server/CDN.

`Compress` и `StaticFiles` принимают необязательный `streams: StreamFactoryInterface`; по умолчанию используется Nyholm PSR-7. Это позволяет сохранить выбранную приложением PSR-17 реализацию при создании тела.

## 405 и завершающий slash

```php
use PhpHono\Middleware\MethodNotAllowed;
use PhpHono\Middleware\TrailingSlash;

$app->use('*', new MethodNotAllowed($app));
$app->get('/items', fn (\PhpHono\Context $c) => $c->json([]));
```

После обычного 404 middleware ищет endpoints того же пути для других методов. При несовпадении возвращает 405 и `Allow`, добавляя HEAD при GET. `use()` не создаёт разрешённые endpoint methods; `all()` означает отсутствие ограничения. Явный `on('HEAD', ...)` не учитывается, поскольку Hono выбирает GET chain для HEAD. Намеренный 404 обработчика того же метода и ошибки через `onError` не заменяются. Без middleware method miss остаётся 404.

```php
$app->use('*', new TrailingSlash()); // Trim: /about/ → /about.
// Альтернатива для нового приложения:
// $app->use('*', new TrailingSlash(append: true, alwaysRedirect: true));
```

Defaults: `append: false`, `alwaysRedirect: false`. По умолчанию redirect выполняется только после обычного 404; существующий маршрут сохраняется. `alwaysRedirect: true` применяет политику перед downstream. Для GET/HEAD используется 301, query string сохраняется; POST и остальные методы не перенаправляются. Корень `/` не изменяется. Эта возможность не меняет default `strict: true` router.
