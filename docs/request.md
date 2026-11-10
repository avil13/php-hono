# Запрос

API php-hono 1.0 для PHP 8.5. [Оглавление](README.md).

В обработчике `$c->req` предоставляет доступ к запросу. Методы чтения не отправляют ответ: результат нужно передать в один из [response helpers](context-response.md).

## Путь, параметры и заголовки

```php
use PhpHono\Context;
use PhpHono\Hono;
use Psr\Http\Message\ResponseInterface;

$app = new Hono();
$app->get('/users/:id', function (Context $c): ResponseInterface {
    return $c->json([
        'id' => $c->req->param('id'),
        'method' => $c->req->method(),
        'path' => $c->req->path(),
        'accept' => $c->req->header('Accept'),
    ]);
});
```

| Метод | Результат |
| --- | --- |
| `method()` | Исходный HTTP-метод, например `GET` или `HEAD` |
| `path()` | Закодированный путь URI; пустой путь возвращается как `/` |
| `url()` | URL запроса |
| `param('id')` | Параметр текущего совпавшего маршрута или `null` |
| `param()` | Карта параметров текущего маршрута |
| `header('Accept')` | Значение заголовка или `null`; регистр имени не важен |
| `header()` | Карта заголовков с именами в нижнем регистре |
| `raw()` | Текущий `ServerRequestInterface`, включая изменения downstream PSR-15 middleware |

Параметры относятся к текущему handler. Глобальный middleware `'*'` не получает заранее параметры endpoint. Значения параметров декодируются после сопоставления маршрута; самостоятельно повторно вызывать `urldecode()` не нужно. `%2F` внутри значения не становится разделителем сегментов до matching. Некорректное percent encoding или UTF-8 в URI даёт 400.

`path()` сохраняет исходный закодированный путь URI, включая завершающий `/` при `strict: false`. Например, запрос `/about/` может совпасть с маршрутом `/about`, но `req->path()` вернёт `/about/`. Это явное отличие от Hono, где при нестрогой маршрутизации request path нормализуется. Исходный путь нужен middleware перенаправления завершающего слеша и static files; `param()` при этом возвращает параметры реально совпавшего маршрута.

Для доступа к PSR-7 attributes используйте `$c->req->raw()->getAttribute('name')`. Модель PSR-7 immutable: вызов `withHeader()` возвращает новый request и сам по себе не меняет запрос внутри Context. Готовые PSR-15 middleware подключаются через адаптер; [bridge](middleware.md#psr-15-middleware) передаёт изменённый request downstream и сохраняет доступность тела через общий ограниченный кеш.

## Query string

`query()` возвращает первое значение каждого параметра, `queries()` сохраняет все значения. Например, для запроса `/search?tag=php&tag=http&q=&user.name=Ann`:

| Вызов | Результат |
| --- | --- |
| `$c->req->query('tag')` | `'php'` |
| `$c->req->queries('tag')` | `['php', 'http']` |
| `$c->req->query('q')` | `''` |
| `$c->req->query('missing')` | `null` |
| `$c->req->query('user.name')` | `'Ann'` |
| `$c->req->query()` | Карта первых значений |
| `$c->req->queries()` | Карта списков значений |

Пустое значение и отсутствующий параметр различаются. Знак `+` декодируется как пробел. Имена с точкой, пробелом и `[]` сохраняются: `tag[]` — отдельное имя параметра, а не обращение к `tag`. Это отличается от PHP `parse_str()` и привычной обработки `$_GET`. Пустое имя параметра допустимо. PHP преобразует целочисленные строковые ключи массива (`"0"`, `"42"`) в `int`; строковые значения не преобразуются.

Пример маршрута; `$app` и импорты берутся из первого примера:

```php
$app->get('/search', function (Context $c): ResponseInterface {
    return $c->json([
        'query' => $c->req->query('q') ?? '',
        'tags' => $c->req->queries('tag') ?? [],
    ]);
});
```

## JSON

`json()` возвращает PHP-значение: JSON object становится `stdClass`, JSON array — массивом. Синтаксически некорректный JSON даёт 400. Проверка формы данных остаётся задачей приложения или validator middleware.

```php
$app->post('/greetings', function (Context $c): ResponseInterface {
    $data = $c->req->json();

    if (!$data instanceof \stdClass || !isset($data->name) || !is_string($data->name)) {
        return $c->json(['error' => 'Ожидается объект со строковым полем name'], 422);
    }

    return $c->json(['message' => 'Привет, ' . $data->name], 201);
});
```

В этом примере 422 выбирает приложение. Сам `req->json()` разбирает тело независимо от `Content-Type`; встроенный JSON Validator проверяет media type и принимает `application/json` либо `application/*+json`. Пустое тело — ошибка JSON 400, а JSON `null` возвращает PHP `null`. Целые числа вне диапазона PHP integer сохраняются parser-ом как строки, чтобы не потерять точность.

Результат `req->valid($target)` предназначен для данных, уже проверенных validator middleware. Вызов без соответствующей validation — ошибка разработчика. Встроенный [Validator](builtin-middleware.md#validator) сохраняет любой результат callback, включая `null`; чтение отсутствующего target выбрасывает `LogicException`.

## Текст и повторное чтение

`text()` возвращает строку тела. Методы `text()`, `json()` и `parseBody()` используют общий кеш: чтение тела в middleware не должно лишать endpoint данных. Даже non-seekable stream считывается один раз через ограниченный reader.

```php
$app->post('/echo', function (Context $c): ResponseInterface {
    return $c->text($c->req->text());
});
```

Прямое чтение `$c->req->raw()->getBody()` обходит этот контракт. Для повторного чтения используйте методы оболочки. По умолчанию общий предел буферизации — **8 MiB (8 388 608 байт)**. Он задаётся конструктором `new Hono(maxBodySize: 8_388_608)`. Превышение даёт 413; reader останавливается не позже limit + 1 байта. Ошибка чтения или превышения сохраняется: повторный вызов не обходит лимит. [BodyLimit](builtin-middleware.md#bodylimit) уменьшает предел для маршрута и проверяет тело до endpoint, даже если тот не читает данные. Сам глобальный предел применяется при чтении тела или входе в PSR-15 bridge; он не заменяет ограничения transport/SAPI.

## URL-encoded формы

Для `application/x-www-form-urlencoded` используйте `parseBody()`:

```php
$app->post('/preferences', function (Context $c): ResponseInterface {
    $form = $c->req->parseBody();
    return $c->json($form);
});
```

Правила повторов отличаются от query string:

| Тело | `parseBody()` | `parseBody(true)` |
| --- | --- | --- |
| `tag=php&tag=http` | `['tag' => 'http']` | `['tag' => ['php', 'http']]` |
| `tag=php` | `['tag' => 'php']` | `['tag' => 'php']` |
| `tag%5B%5D=php` | `['tag[]' => ['php']]` | `['tag[]' => ['php']]` |

По умолчанию берётся последнее повторяющееся значение. Аргумент `true` включает сбор повторов, но не превращает одиночные обычные поля в массивы. Поле с именем `tag[]` всегда возвращается массивом под тем же именем. Раскрытие точек во вложенные объекты (`dot:true`) не поддерживается. Для прочих media types `parseBody()` возвращает `[]`; метод не пытается угадать формат.

## Multipart и загрузка файлов

Multipart разбирается SAPI/адаптером до вызова приложения. `parseBody()` использует готовые поля PSR request, а `uploadedFiles()` возвращает загруженные файлы в виде `UploadedFileInterface` или вложенных массивов таких объектов.

```php
use Psr\Http\Message\UploadedFileInterface;

$app->post('/upload-info', function (Context $c): ResponseInterface {
    $form = $c->req->parseBody();
    $files = $c->req->uploadedFiles();
    $file = $files['attachment'] ?? null;

    if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
        return $c->json(['error' => 'Нужен успешно загруженный attachment'], 422);
    }

    return $c->json([
        'description' => $form['description'] ?? '',
        'size' => $file->getSize(),
    ]);
});
```

Не используйте присланное клиентом имя файла как путь сохранения. Пример читает только метаданные и ничего не сохраняет.

Ограничения этого пути:

- PHP уже может изменить имена полей и потерять повторы; полного соответствия raw multipart Hono нет.
- `parseBody(true)` для multipart выбрасывает `InvalidArgumentException`.
- Адаптер отмечает успешно подготовленный multipart, включая пустую форму, атрибутом `php_hono.multipart_parsed=true`. Без него `parseBody()` возвращает ошибку 415.
- При создании PSR request в тестах сначала подготовьте parsed body/uploaded files, затем устанавливайте marker. Marker не заменяет parser.
- `post_max_size`, upload limits и лимиты веб-сервера действуют до framework. Когда SAPI не оставил тело, размер или признак ошибки, приложение не всегда может отличить потерянные данные от пустой формы.

## Изменения запроса в PSR-15 bridge

Bridge предоставляет read-only replay stream из общего кеша: его можно читать и перематывать, а `write()` запрещён. Для изменения тела middleware передаёт новый request через `withBody()`. Новый stream получает собственный кеш с тем же уменьшенным лимитом; значения JSON/form и результаты их validation сбрасываются. Изменения headers, Cookie, Content-Type или parsed body сбрасывают соответствующие результаты validation. После возврата из bridge предыдущий request view восстанавливается. Внутренние методы `bodyStore()`, `withRaw()`, `setParams()` и `setValidated()` предназначены для адаптеров framework; в приложении используйте read helpers, Validator и PSR-15.

Далее: [Context и ответы](context-response.md), [обработка ошибок](errors.md), [тестирование](testing.md).
