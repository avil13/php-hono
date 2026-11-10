# Начало работы

[Оглавление](README.md) · Реализованный API 1.0, PHP 8.5.

## Требования и установка

Нужны PHP **8.5+** и Composer. HTTP-объекты основаны на PSR-7/17; реализацией по умолчанию служит `nyholm/psr7`. Для тестов приложения используется PHPUnit. Node.js не нужен пользователю PHP-фреймворка.

Пакет называется `php-hono/php-hono`; внешняя публикация пока не выполнялась. Из checkout репозитория выполните:

```sh
composer install
composer tools:prepare
composer check
php -S 127.0.0.1:8080 -t examples/public examples/public/index.php
```

Для отдельного приложения до публикации подключите локальный checkout через path repository:

```json
{
  "repositories": [{
    "type": "path",
    "url": "/absolute/path/to/php-hono",
    "options": {"versions": {"php-hono/php-hono": "1.0.0"}}
  }],
  "require": {"php": "^8.5", "php-hono/php-hono": "^1.0"}
}
```

В каталоге приложения запустите `composer install`. Указанная path-version нужна локальному Composer и не означает опубликованный release. Runtime dependencies включают Nyholm и PSR interfaces; Mago/PHPUnit/PHPStan являются dev tools самого фреймворка.

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

`app.php` регистрирует маршруты и возвращает приложение. `public/index.php` соединяет его с сервером. Такое разделение позволяет использовать те же маршруты в HTTP-запросах и тестах. Front controller:

```php
<?php
declare(strict_types=1);
$app = require dirname(__DIR__) . '/app.php';
\PhpHono\Adapter\Sapi::run($app);
```

Конфигурация FPM и worker описана в [deployment](deployment.md).

## Первое приложение

Содержимое `app.php`:

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

Пример `check.php` рядом с `app.php`:

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
