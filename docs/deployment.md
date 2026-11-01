# Запуск приложения

[Оглавление](README.md) · План интеграции для PHP 8.5+. Готового SAPI bootstrap в репозитории пока нет.

## Три способа вызвать приложение

| Метод | Назначение | Отправляет ответ клиенту |
| --- | --- | --- |
| `$app->request($path, $options, $env)` | Тест или вызов в памяти; также принимает ServerRequest | Нет |
| `$app->handle($request)` | Вход PSR-15 RequestHandlerInterface | Нет |
| `$app->fetch($request, $env)` | Синхронный вход с bindings текущего запроса | Нет |

Все три метода возвращают PSR-7 ResponseInterface. `fetch()` не является исходящим HTTP-клиентом. Отправка headers/body клиенту — задача runtime adapter и emitter.

## Передача готового PSR-7 запроса

Этот пример использует [Nyholm PSR-7](https://github.com/Nyholm/psr7), выбранный в архитектуре проекта. `$app` загружается из [app.php](getting-started.md):

```php
<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;

$app = require __DIR__ . '/app.php';
$factory = new Psr17Factory();

$request = $factory
    ->createServerRequest('POST', 'http://localhost/echo')
    ->withHeader('Content-Type', 'application/json')
    ->withBody($factory->createStream('{"name":"Ada"}'));

$response = $app->handle($request);
```

Bindings можно передать отдельно: `$app->fetch($request, ['APP_MODE' => 'test'])`. Они относятся только к этому запросу. Для `handle()` env берётся из конфигурации приложения; точная сигнатура этой конфигурации ещё не определена. Не заменяйте её чтением глобальных переменных в каждом handler.

## PHP-FPM и SAPI

Целевой front controller выполняет три действия:

1. Преобразует вход SAPI в ServerRequest, включая headers, raw target, parsed multipart и uploaded files.
2. Передаёт запрос в `$app->handle()`.
3. Отправляет Response через emitter с поддержкой нескольких Set-Cookie, HEAD, bodyless statuses и streams.

Для создания запроса запланирован [nyholm/psr7-server](https://github.com/Nyholm/psr7-server). Обёртка php-hono также должна проверять доступные ошибки upload/body limits и отмечать результат multipart parsing. Простая подстановка `$_POST` не обеспечивает весь контракт.

Публичные сигнатуры `Sapi` и `SapiEmitter` ещё не закреплены. Поэтому здесь нет фиктивного `Sapi::run()` и готового `public/index.php`: законченный bootstrap должен появиться вместе с T11. Замена emitter на `echo (string) $response->getBody()` теряет status, headers и правила HEAD.

После появления bootstrap web server должен направлять application requests в `public/index.php`; document root — `public/`. `vendor`, конфигурация и исходники приложения не должны становиться статическими файлами сайта. Путь к PHP-FPM socket и настройки сервера зависят от окружения.

## Локальный сервер PHP

Когда `public/index.php` будет подключён к реализованному SAPI adapter, локальный запуск из каталога приложения будет выглядеть так:

```sh
php -S 127.0.0.1:8080 -t public public/index.php
```

Исполняемый `php` должен быть версии 8.5+. Здесь `public/index.php` используется и как router script, чтобы запросы попадали в приложение. Раздача assets в этой схеме отдельно не показана.

Встроенный сервер предназначен для разработки и контролируемых проверок; для production предусмотрен PHP-FPM или поддержанный worker runtime. [Документация PHP](https://www.php.net/manual/en/features.commandline.webserver.php).

## Настройки HTTP-границы

- Согласуйте лимиты web server, PHP `post_max_size`/`upload_max_filesize` и приложения. Для multipart PHP может прочитать тело до вызова framework, поэтому middleware не заменяет серверные лимиты.
- До начала ответа не выводите отладочный текст из handlers: он мешает корректной отправке headers. Диагностику направляйте в лог.
- Не доверяйте `X-Forwarded-*` автоматически. Trusted proxy, внешний scheme/host и адрес клиента настраиваются на стороне adapter/server.
- Новый Context не очищает singleton или изменяемые данные, захваченные closure. Учитывайте это при повторных вызовах App.

## Версия 1.0: worker и streaming

FrankenPHP worker mode запланирован отдельным адаптером: приложение загружается один раз, request state создаётся заново на каждом запросе. Конкретные startup command, версия runtime и cleanup contract появятся после T29. Само наличие PSR-15 handler не означает, что worker integration уже готова.

HTTP streaming/SSE планируются в T28. Producer должен запускаться emitter после подготовки headers, отдавать данные без полного буферизования и освобождать ресурсы при завершении. После отправки headers `onError` не сможет заменить HTTP-статус; такую ошибку нужно логировать и завершать поток. Настройки proxy buffering влияют на получение chunks клиентом.

Конкретные сигнатуры streaming helpers ещё не утверждены. JSX и WebSocket не планируются; SSE остаётся самостоятельной HTTP-возможностью.
