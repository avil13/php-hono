# Тестирование приложения

[Оглавление](README.md) · Примеры для проектного API MVP. Пока реализации нет, это будущие тесты контракта, а не уже прошедший набор.

Format/lint, PHPStan и pre-commit входят в общий набор [проверок разработки](development.md). Hook проверяет staged PHP-код через Mago; полный набор тестов и проверку зависимостей по lockfile выполняет CI.

## Инструменты

Основной инструмент плана — PHPUnit. Его assertions и data providers подходят для проверки HTTP-ответов и таблиц маршрутов. Версия PHPUnit и точная конфигурация Composer будут закреплены при создании пакета; отдельного обязательного php-hono test runner не планируется. Обычный формат теста соответствует [документации PHPUnit](https://docs.phpunit.de/en/12.5/writing-tests-for-phpunit.html).

Установка самого фреймворка описана в [начале работы](getting-started.md). После появления библиотеки и установки PHPUnit файл теста можно будет запускать так:

```sh
vendor/bin/phpunit --bootstrap vendor/autoload.php tests/AppTest.php
```

Сейчас эта команда в репозитории ещё не работает: `vendor` и реализация отсутствуют.

## Проверка маршрута

Полный пример `tests/AppTest.php`:

```php
<?php

declare(strict_types=1);

use PhpHono\Context;
use PhpHono\Hono;
use PHPUnit\Framework\TestCase;

final class AppTest extends TestCase
{
    public function testUserRouteReturnsJson(): void
    {
        $app = new Hono();
        $app->get('/users/:id', fn (Context $c) =>
            $c->json(['id' => $c->req->param('id')])
        );

        $response = $app->request('/users/42');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            ['id' => '42'],
            json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testHeadUsesGetRouteWithoutResponseBody(): void
    {
        $app = new Hono();
        $app->get('/', fn (Context $c) =>
            $c->text('Hello', 200, ['X-Method' => $c->req->method()])
        );

        $response = $app->request('/', ['method' => 'HEAD']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('HEAD', $response->getHeaderLine('X-Method'));
        self::assertSame('', (string) $response->getBody());
    }

    public function testMalformedJsonReturnsBadRequest(): void
    {
        $app = new Hono();
        $app->post('/echo', fn (Context $c) => $c->json($c->req->json()));

        $response = $app->request('/echo', [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'body' => '{',
        ]);

        self::assertSame(400, $response->getStatusCode());
    }
}
```

JSON сравнивайте как структуру, когда тестируете данные API. Для text, binary body и форматирования HTML проверяйте bytes. Несколько Set-Cookie проверяйте через `getHeader('Set-Cookie')`, а не склеенную строку `getHeaderLine()`.

## Один набор данных — несколько сценариев

Отдельный пример класса `RouteTest`:

```php
<?php

declare(strict_types=1);

use PhpHono\Context;
use PhpHono\Hono;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RouteTest extends TestCase
{
    public static function paths(): iterable
    {
        yield 'exact' => ['/users/42', 200];
        yield 'strict trailing slash' => ['/users/42/', 404];
        yield 'missing segment' => ['/users', 404];
    }

    #[DataProvider('paths')]
    public function testPath(string $path, int $expectedStatus): void
    {
        $app = new Hono();
        $app->get('/users/:id', fn (Context $c) => $c->text('Found'));

        self::assertSame($expectedStatus, $app->request($path)->getStatusCode());
    }
}
```

Регистрируйте все маршруты до первого `request()`. Добавление маршрутов между запросами одного экземпляра не поддерживается: таблица уже заморожена.

## Проверка состояния между запросами

Этот метод можно добавить в класс PHPUnit `TestCase`. Он намеренно использует один экземпляр приложения:

```php
public function testContextDoesNotLeakBetweenRequests(): void
{
    $app = new \PhpHono\Hono();
    $app->get('/', function (\PhpHono\Context $c) {
        $wasPresent = $c->has('visited');
        $c->set('visited', true);

        return $c->json(['wasPresent' => $wasPresent]);
    });

    foreach ([1, 2] as $requestNumber) {
        $response = $app->request('/');
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse($data['wasPresent'], 'Request ' . $requestNumber);
    }
}
```

Так проверяется изоляция Context. Данные, которые приложение само сохранило в mutable closure, singleton или static property, автоматически не очищаются.

## Когда нужен реальный сервер

`request()` проверяет маршруты, middleware и HTTP messages, но не работу FPM или отправку bytes клиенту. Отдельно проверяйте SAPI uploads, ограничения размера на стороне PHP/web server, несколько Set-Cookie в HTTP-ответе и отсутствие body у HEAD. Для будущей версии 1.0 понадобятся реальные worker/streaming проверки: сброс состояния после ошибок, получение chunks до конца producer и отключение клиента.

Multipart-тест в памяти должен передавать подготовленный PSR request и признак успешного разбора; raw multipart body само по себе не разбирается в MVP. Детали приведены в [Request](request.md).

Сам фреймворк дополнительно будет проверяться эталонными сценариями зафиксированного Hono 4.13.16. Приложению этот JS harness не нужен. Совпадение нескольких ответов не означает поддержку всех Hono API — сверяйтесь с [матрицей совместимости](compatibility.md).
