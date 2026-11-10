# Запуск приложения

[English](../en/deployment.md) | **Русский**

[Оглавление](README.md) · PHP 8.5+

`Hono::request()`, `handle()` и `fetch()` возвращают PSR-7 Response. Для отправки клиенту используйте SAPI adapter. `fetch()` — синхронная обработка входящего запроса, а не HTTP-клиент.

## PHP-FPM и локальный сервер

Регистрация маршрутов возвращает приложение из `app.php`; front controller подключает autoload и передаёт приложение адаптеру:

```php
<?php

declare(strict_types=1);

use PhpHono\Adapter\Sapi;

require dirname(__DIR__) . '/vendor/autoload.php';

Sapi::run(require dirname(__DIR__) . '/app.php');
```

Готовый пример находится в [examples/public/index.php](../../examples/public/index.php). Из корня репозитория:

```sh
php -S 127.0.0.1:8080 -t examples/public examples/public/index.php
```

В production document root должен указывать на `public/`, а application routes направляться в `index.php`. Исходники, `vendor/`, конфигурация и тестовые fixtures не должны раздаваться как статические файлы. Встроенный PHP-сервер подходит для разработки; production profile — FPM или FrankenPHP worker.

`Sapi::request()` создаёт Nyholm ServerRequest, сохраняет raw request target, cookies, parsed fields и uploaded files. До нормализации URI проверяется кодирование пути. Превышение PHP `post_max_size` и ошибки размера upload дают 413; частичный upload — 400, серверные ошибки записи upload — 500. Для POST multipart с включённым PHP parser устанавливается attribute `php_hono.multipart_parsed`. Синтаксис MIME вручную не разбирается; PHP и сервер могут прочитать тело до запуска приложения.

Согласуйте `post_max_size`, `upload_max_filesize`, ограничения сервера и middleware `BodyLimit`. Adapter не доверяет `X-Forwarded-Proto` при выборе scheme: forwarded headers остаются доступны приложению, но доверие proxy настраивается явно вне ядра. Клиентский адрес берётся из SAPI `REMOTE_ADDR`.

## Собственный PSR-15 pipeline

Внешний handler может менять URI/method до входа в Hono. Передайте итоговый `RequestHandlerInterface` в `Sapi::run($pipeline)`. Для самостоятельного управления границей:

```php
use PhpHono\Adapter\Sapi;
use PhpHono\Adapter\SapiEmitter;

$request = Sapi::request();
$response = $pipeline->handle($request);
(new SapiEmitter())->emit($response, head: strtoupper($request->getMethod()) === 'HEAD');
```

Emitter сохраняет отдельные `Set-Cookie`, подавляет body для HEAD и статусов без тела, читает body порциями и закрывает его в `finally`. Не заменяйте его на `echo (string) $response->getBody()`: такой код теряет HTTP metadata и буферизует поток. Вариант `Sapi::run()` дополнительно преобразует ошибки подготовки запроса в безопасный HTTP-ответ.

## Streaming и SSE

Producer возвращает `iterable<string>` и запускается при первом чтении body. Чтение status/headers/metadata не вызывает producer. HEAD не запускает producer вообще.

```php
use PhpHono\Context;
use PhpHono\Helper\Streaming;

$app->get('/stream', static function (Context $context) {
    return Streaming::streamText($context, static function (): Generator {
        try {
            yield "first\n";
            yield "second\n";
        } finally {
            // Здесь освобождаются ресурсы producer.
        }
    });
});

$app->get('/events', static function (Context $context) {
    return Streaming::streamSSE($context, static function (): Generator {
        yield ['event' => 'update', 'id' => '42', 'retry' => 1000, 'data' => "line one\nline two"];
    });
});
```

`Streaming::stream()` не задаёт Content-Type; `streamText()` задаёт `text/plain; charset=UTF-8`; `streamSSE()` — `text/event-stream; charset=UTF-8`, `Cache-Control: no-cache` и `X-Accel-Buffering: no`. Необязательные аргументы `status` и `headers` позволяют изменить ответ; без status сохраняется `Context::status()`. SSE `data` — строка, поэтому JSON кодируется приложением. `event`/`id` не допускают переносов строк и NUL, `retry` задаётся неотрицательным числом миллисекунд.

`StreamingBody` — single-use, read-only, non-seekable PSR-7 stream с неизвестным размером. Явный `getContents()` или приведение к строке вычитывает оставшийся поток целиком. Middleware `ETag` и `Compress` пропускают StreamingBody. Стороннее middleware, читающее body целиком, может устранить преимущества streaming.

Emitter делает flush после каждого доступного chunk и прекращает чтение после обнаружения отключения клиента. Отложенные producer освобождаются через `close()`; `finally` generator выполняется при отмене начатого потока. Создавайте generator внутри producer и не сохраняйте внешние ссылки на него. Обнаружение отключения зависит от SAPI/proxy и обычно происходит после очередной записи. Блокирующий producer невозможно принудительно прервать между yield.

После отправки headers ошибка producer уже не может изменить статус: emitter закрывает поток и пишет класс ошибки в лог. Свой обработчик диагностики передаётся через `new SapiEmitter(onError: $callback)`. Он получает Throwable, не отправляет второй HTTP-ответ. Настройки output buffering, reverse proxy и compression могут задерживать доставку данных; проверяйте фактическую инфраструктуру.

## FrankenPHP worker

[examples/worker.php](../../examples/worker.php) загружает приложение один раз:

```php
use PhpHono\Adapter\FrankenPhpWorker;

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/app.php';
FrankenPhpWorker::run($app, maxRequests: 500);
```

`FrankenPhpWorker::run(RequestHandlerInterface $app, int $maxRequests = 500, ?callable $cleanup = null)` использует настоящий `frankenphp_handle_request`. В обычном PHP CLI вызов завершается понятной ошибкой. `maxRequests` должен быть положительным: после лимита adapter возвращает управление, а FrankenPHP запускает worker заново. `cleanup` вызывается после каждого обработанного запроса, включая ошибку. Сборщик циклов выполняется после cleanup внутри callback, до возврата управления native runtime, также при исключении из cleanup.

Минимальный Caddyfile для примера, запускаемый из корня репозитория:

```caddyfile
{
    frankenphp
}
:8080 {
    root * examples/public
    php_server {
        worker {
            file examples/worker.php
            num 1
            match *
        }
    }
}
```

Запуск: `frankenphp run --config Caddyfile`. Для production задайте адрес сайта, количество workers, лимиты и перезапуск через процессный менеджер. API и конфигурация сверены с [FrankenPHP worker documentation](https://frankenphp.dev/docs/worker/) и [configuration reference](https://frankenphp.dev/docs/config/). Интеграционные проверки проекта закрепляют FrankenPHP 1.13.5 с PHP 8.5.

Каждый запрос получает новый Context, request wrapper и body cache. Объекты middleware, handlers и захваченные сервисы могут жить весь worker lifecycle. Request-scoped сервисы создавайте внутри middleware/factory; shared сервисы не должны хранить текущего пользователя или Context. PSR-15 сам по себе не гарантирует worker safety стороннего пакета. Разделение lifecycle и DI описано в [Context extensions](context-extensions.md).

## Воспроизводимая проверка транспорта

После `composer install`:

```sh
tools/runtime/test-fpm.sh
FRANKENPHP_BINARY=/absolute/path/to/frankenphp tools/runtime/test-worker.sh
```

FPM script использует Docker Compose, PHP 8.5 и Caddy с закреплёнными digest; worker script требует FrankenPHP 1.13.5/PHP 8.5. Оба требуют Python 3 standard library и останавливают созданные серверы после проверки. Результаты и логи сохраняются в `var/runtime-*-result.json` и `var/runtime-*.log`.

Проверяются multipart/file uploads, PHP size limits, HEAD/204/205, отдельные cookies, первый chunk до завершения producer, ошибка после headers и отмена при отключении клиента. Worker дополнительно проходит 100 последовательных probes с чередованием ошибок/401/422, third-party PSR-15 middleware, typed Identity/Tenant scope, constructor DI, request factory и явным PSR mapper. WeakReference проверяют освобождение прошлых Context, identity и scope; проверяются validation isolation, cleanup и несколько перезапусков при `maxRequests=20`.

Для длительной проверки без рестартов:

```sh
FRANKENPHP_BINARY=/absolute/path/to/frankenphp \
PHP_HONO_TEST_PROBES=5000 \
PHP_HONO_TEST_MAX_REQUESTS=25000 \
PHP_HONO_TEST_SINGLE_BOOT=1 \
PHP_HONO_TEST_RESULT=var/runtime-soak-result.json \
tools/runtime/test-worker.sh
```

Один probe включает успешный запрос и проверки отказов: 5000 probes дают более 18 000 HTTP-запросов. Поэтому лимит 25 000 сохраняет один worker на всём прогоне. `PHP_HONO_TEST_SINGLE_BOOT=1` требует ровно один boot; обычный режим, наоборот, проверяет рестарты. `check.py` также принимает `--probes` и `--single-boot` при ручном запуске против уже поднятого сервера. HTTP keep-alive позволяет проводить длительный прогон без исчерпания клиентских TCP-портов.

Результат содержит число запросов/boots, первый и последний замеры PHP memory, диапазон памяти и число удержанных объектов. После первых десяти probes разброс памяти должен быть меньше 1 MiB, прошлые request-объекты не должны удерживаться. Это проверка lifecycle в заданном сценарии, а не гарантия отсутствия утечек в любом приложении.

На FrankenPHP 1.13.5/PHP 8.5.12 этот прогон обнаружил рост heap при сборке циклов только после `frankenphp_handle_request()`: 5000 probes увеличивали память с 771 616 до 1 908 736 байт, хотя weak references уже были пустыми. Перенос GC внутрь callback дал 18 346 HTTP-запросов в одном boot с памятью 771 624–771 696 байт. Сравнение указывает на необходимость освобождать циклы exception/request до native cleanup входного потока; это исправление порядка cleanup в адаптере, а не заявление об исправлении upstream runtime. CI запускает и короткий restart test, и этот single-boot regression.
