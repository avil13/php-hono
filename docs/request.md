# Запрос

Проектная документация API MVP для PHP 8.5+. Реализации пока нет; namespace `PhpHono` предварительный. [Оглавление](README.md).

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
| `path()` | Путь, используемый приложением для маршрутизации |
| `url()` | URL запроса |
| `param('id')` | Параметр текущего совпавшего маршрута или `null` |
| `param()` | Карта параметров текущего маршрута |
| `header('Accept')` | Значение заголовка или `null`; регистр имени не важен |
| `header()` | Карта заголовков с именами в нижнем регистре |
| `raw()` | Исходный `ServerRequestInterface` |

Параметры относятся к текущему handler. Глобальный middleware `'*'` не получает заранее параметры endpoint. Значения параметров декодируются после сопоставления маршрута; самостоятельно повторно вызывать `urldecode()` не нужно. `%2F` внутри значения не становится разделителем сегментов до matching. Некорректное percent encoding или UTF-8 в URI даёт 400.

Для доступа к PSR-7 attributes используйте `$c->req->raw()->getAttribute('name')`. Модель PSR-7 immutable: вызов `withHeader()` возвращает новый request и сам по себе не меняет запрос внутри Context. Подключение готовых PSR-15 middleware входит в обязательный MVP; [bridge](middleware.md#psr-15-middleware) передаёт изменённый request downstream и сохраняет доступность тела через общий ограниченный кеш.

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

Пустое значение и отсутствующий параметр различаются. Знак `+` декодируется как пробел. Имена с точкой, пробелом и `[]` сохраняются: `tag[]` — отдельное имя параметра, а не обращение к `tag`. Это отличается от PHP `parse_str()` и привычной обработки `$_GET`.

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

В этом примере 422 выбирает приложение; это не автоматический ответ JSON parser. Отправляйте `Content-Type: application/json`. Целые числа вне диапазона PHP integer сохраняются parser-ом как строки, чтобы не потерять точность.

Результат `req->valid($target)` предназначен для данных, уже проверенных validator middleware. Вызов без соответствующей validation — ошибка разработчика. Контракт и статус встроенного validator описаны в [middleware](middleware.md).

## Текст и повторное чтение

`text()` возвращает строку тела. Методы `text()`, `json()` и `parseBody()` используют общий кеш: чтение тела в middleware не должно лишать endpoint данных. Даже non-seekable stream считывается один раз через ограниченный reader.

```php
$app->post('/echo', function (Context $c): ResponseInterface {
    return $c->text($c->req->text());
});
```

Прямое чтение `$c->req->raw()->getBody()` обходит этот контракт. Для повторного чтения используйте методы оболочки. В MVP запланирован конечный предел буферизации; исходное предложение — 8 MiB, значение ещё должно быть закреплено перед реализацией. `bodyLimit` позволяет ограничивать маршруты; детали — в [middleware](middleware.md).

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

По умолчанию берётся последнее повторяющееся значение. Аргумент `true` включает сбор повторов, но не превращает одиночные обычные поля в массивы. Поле с именем `tag[]` всегда возвращается массивом под тем же именем. Раскрытие точек во вложенные объекты (`dot:true`) не входит в MVP.

## Multipart и загрузка файлов

В MVP multipart разбирается SAPI/адаптером до вызова приложения. `parseBody()` использует готовые поля PSR request, а `uploadedFiles()` возвращает загруженные файлы в виде `UploadedFileInterface` или вложенных массивов таких объектов.

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
- `parseBody(true)` для multipart не поддерживается и должно давать явную ошибку.
- Адаптер отмечает успешно подготовленный multipart, включая пустую форму, атрибутом `php_hono.multipart_parsed=true`. Без него `parseBody()` возвращает ошибку 415.
- При создании PSR request в тестах сначала подготовьте parsed body/uploaded files, затем устанавливайте marker. Marker не заменяет parser.
- `post_max_size`, upload limits и лимиты веб-сервера действуют до framework. Когда SAPI не оставил тело, размер или признак ошибки, приложение не всегда может отличить потерянные данные от пустой формы.

Далее: [Context и ответы](context-response.md), [обработка ошибок](errors.md), [тестирование](testing.md).
