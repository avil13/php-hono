# Начало работы

[Оглавление](README.md) · Проектное руководство для MVP; реализации пакета пока нет.

## Требования и установка

Нужны PHP **8.5+** и Composer. HTTP-объекты основаны на PSR-7/17; реализацией по умолчанию запланирован `nyholm/psr7`. Для тестов приложения используется PHPUnit. Node.js не нужен пользователю PHP-фреймворка.

Имя Composer-пакета ещё не выбрано, а `composer.json` отсутствует. Поэтому команды установки php-hono сейчас нет. После выпуска пакета здесь появится точная команда `composer require` с опубликованным именем. Следующие примеры предполагают, что пакет уже подключён и существует `vendor/autoload.php`.

## Структура небольшого приложения

```text
my-api/
  app.php
  public/
    index.php
  tests/
    AppTest.php
  vendor/
```

`app.php` регистрирует маршруты и возвращает приложение. `public/index.php` соединяет его с сервером. Такое разделение позволяет использовать те же маршруты в HTTP-запросах и тестах. Содержимое front controller будет зависеть от окончательного [SAPI-адаптера](deployment.md).

## Первое приложение

Целевое содержимое `app.php`:

```php
<?php

declare(strict_types=1);

use PhpHono\Context;
use PhpHono\Hono;
use Psr\Http\Message\ResponseInterface;

require __DIR__ . '/vendor/autoload.php';

$app = new Hono();

$app->get('/', fn (Context $c): ResponseInterface =>
    $c->text('Hello, PHP Hono!')
);

$app->get('/hello/:name', fn (Context $c): ResponseInterface =>
    $c->json(['message' => 'Hello, ' . $c->req->param('name') . '!'])
);

$app->post('/echo', fn (Context $c): ResponseInterface =>
    $c->json($c->req->json())
);

return $app;
```

Handler — PHP callable. `$c->req` содержит входной запрос, а `$c->text()` и `$c->json()` создают ответ. Ответ нужно **вернуть**: вызов `$c->text('OK')` без `return` не завершает обработку корректно.

JSON-объект на входе декодируется в `stdClass`; JSON-массив — в PHP array. В примере `/echo` значение передаётся обратно без преобразований.

## Проверка без сервера

Пример `check.php` рядом с `app.php`, который можно будет выполнить после реализации библиотеки:

```php
<?php

declare(strict_types=1);

$app = require __DIR__ . '/app.php';

$response = $app->request('/hello/Ada');

printf("Status: %d\n", $response->getStatusCode());
printf("Body: %s\n", (string) $response->getBody());
```

Ожидается статус 200 и JSON с полем `message`, равным `Hello, Ada!`. `request()` не открывает соединение и не запускает веб-сервер.

Для POST укажите method, headers и строковое body:

```php
$response = $app->request('/echo', [
    'method' => 'POST',
    'headers' => ['Content-Type' => 'application/json'],
    'body' => json_encode(['name' => 'Ada'], JSON_THROW_ON_ERROR),
]);
```

Используйте только закреплённые options: `method`, `headers`, `body`. Параметров HTTP-клиента вроде `timeout` или `followRedirects` у этого helper нет.

## Добавление middleware

Зарегистрируйте middleware **перед** маршрутами, к которым он должен применяться:

```php
use PhpHono\Context;
use PhpHono\Hono;

$app = new Hono();

$app->use('*', function (Context $c, callable $next): void {
    $next();
    $c->header('X-App', 'php-hono');
});

$app->get('/', fn (Context $c) => $c->text('OK'));
```

Код после `$next()` работает с подготовленным downstream-ответом. Здесь он добавляет header к результату маршрута.

## Следующие шаги

Для нескольких endpoint объединяйте приложения через [route/basePath](routing.md). Чтение JSON и форм описано в [Request](request.md), поведение ошибок — в [Errors](errors.md). Перед подключением сервера напишите [тесты через request()](testing.md): они проверяют приложение без сетевой инфраструктуры.
