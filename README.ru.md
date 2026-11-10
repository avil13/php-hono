# php-hono

[English](README.md) | **Русский**

HTTP-фреймворк для **PHP 8.5** с API в стиле [Hono](https://github.com/honojs/hono), PSR-7 ответами и поддержкой сторонних PSR-15 middleware. Реализован выбранный профиль 1.0: ordered routing, Context extensions, validation, HTTP middleware, streaming/SSE, SAPI/FPM и FrankenPHP worker.

Это самостоятельная реализация, а не официальный порт. JSX и WebSocket исключены. Границы совместимости описаны в [руководстве](docs/ru/compatibility.md).

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use PhpHono\Context;
use PhpHono\Hono;
use PhpHono\Middleware\Psr15;

$app = new Hono();
$app->get('/hello/:name', fn (Context $c) =>
    $c->json(['hello' => $c->req->param('name')])
);

$response = $app->request('/hello/Ada');
echo $response->getBody(); // {"hello":"Ada"}
```

Из checkout репозитория:

```sh
composer install
composer tools:prepare
composer check
composer hooks:install
php -S 127.0.0.1:8080 -t examples/public examples/public/index.php
curl http://127.0.0.1:8080/hello/Ada
```

Пакет подготовлен под именем `php-hono/php-hono`, namespace `PhpHono`. Публикация в Packagist и release tag не выполнялись; до публикации используйте checkout или локальный Composer path/artifact repository, как описано в [установке](docs/ru/getting-started.md).

- [Документация](docs/ru/README.md), [API reference](docs/ru/api.md), [встроенные middleware](docs/ru/builtin-middleware.md).
- [PSR-15 bridge](docs/ru/middleware.md#psr-15-middleware), [Context и DI](docs/ru/context-extensions.md).
- [Запуск и streaming](docs/ru/deployment.md), [разработка и pre-commit](docs/ru/development.md).
- [План и статус реализации](spec/implementation-status.md), [измерения](benchmarks/README.ru.md).

Проверки: Mago **1.59.0** (format + строгий lint), PHPStan **max**, PHPUnit; offline fixtures настоящего Hono **4.13.16** на закреплённом commit. Node нужен только для регенерации fixtures и вспомогательных HTTP-прогонов, не для работы библиотеки.

Лицензия [MIT](LICENSE); сведения об upstream — [THIRD_PARTY_NOTICES](THIRD_PARTY_NOTICES.md).
