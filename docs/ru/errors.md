# Ошибки и ответы 404

[English](../en/errors.md) | **Русский**

API 1.0 для PHP 8.5, namespace `PhpHono`. [Оглавление](README.md).

Ожидаемую ошибку приложения можно выразить обычным HTTP-ответом. Необработанный `Throwable` передаётся в `onError`. По умолчанию framework возвращает для неизвестной ошибки 500 с текстом `Internal Server Error`, не раскрывая клиенту stack trace.

## Ожидаемый отказ

Возврат Response останавливает handler; исключение для этого не обязательно:

```php
use PhpHono\Context;
use PhpHono\Hono;
use Psr\Http\Message\ResponseInterface;

$app = new Hono();
$app->get('/greeting', function (Context $c): ResponseInterface {
    $name = $c->req->query('name');

    if ($name === null || $name === '') {
        return $c->json(['error' => 'Укажите name'], 422);
    }

    return $c->json(['message' => 'Привет, ' . $name]);
});
```

В примерах ниже используются `$app` и импорты из этого блока. Выбирайте статус по контракту своего API: framework не превращает произвольные ошибки проверки данных в 422 автоматически.

## Свой notFound

```php
$app->notFound(fn (Context $c): ResponseInterface =>
    $c->json(['error' => 'Маршрут не найден'], 404)
);
```

Этот callback вызывается при отсутствии подходящего маршрута и при завершении цепочки без ответа. Из handler его можно вызвать явно:

```php
$app->get('/removed-page', fn (Context $c): ResponseInterface => $c->notFound());
```

Несовпадение HTTP-метода по умолчанию тоже даёт 404. Автоматический 405 не входит в ядро; доступен opt-in middleware `new \PhpHono\Middleware\MethodNotAllowed($app)`. OPTIONS обрабатывается явно зарегистрированным handler либо CORS middleware.

После `route('/api', $child)` применяется notFound корневого приложения; дочерний notFound не импортируется.

## Свой onError

Callback получает `Throwable` и Context, затем возвращает `ResponseInterface`:

```php
$app->onError(function (\Throwable $error, Context $c): ResponseInterface {
    error_log('Request failed: ' . get_class($error));
    return $c->json(['error' => 'Внутренняя ошибка сервера'], 500);
});
```

Этот минимальный пример намеренно переводит все поступившие исключения в 500. Пользовательский `onError` заменяет стандартную политику; если API должен сохранять HTTP-статусы parser/HttpException, проверяйте `$error instanceof \PhpHono\HttpException` и используйте его `$status` и `$response`. Либо оставьте стандартный handler для этих статусов.

Не возвращайте клиенту `$error->getMessage()` или trace автоматически: они могут содержать внутренние детали. Выберите безопасное публичное сообщение, а диагностику направляйте в серверный журнал. Пример логирует только класс ошибки.

Если пользовательский `onError` сам бросает исключение, framework один раз применяет встроенный fallback 500 без повторного вызова пользовательского callback. Дочерний `onError`, импортированный через `route()`, имеет приоритет для соответствующих маршрутов.

## HttpException

`PhpHono\HttpException` хранит HTTP status, message, необязательный готовый Response и предыдущее исключение. Стандартный обработчик использует этот HTTP-ответ; статус исключения имеет приоритет над статусом вложенного Response.

```php
throw new \PhpHono\HttpException(409, 'Conflict');
```

Сигнатура: `__construct(int $status, string $message = '', ?ResponseInterface $response = null, ?Throwable $previous = null)`. Публичные readonly свойства — `$status` и `$response`; сообщение доступно через `getMessage()`. Статус должен быть 400–599. Передавайте в message только предназначенный клиенту текст.

## Ошибки middleware

Исключение из downstream handler обрабатывается до возвращения из `$next()`. Upstream middleware после `$next()` может добавить заголовки к error response:

```php
$app->use('*', function (Context $c, callable $next): void {
    $next();
    $c->header('X-App', 'php-hono');
});
```

Зарегистрируйте такое middleware до endpoint. Если оно само бросает исключение после `$next()`, ошибка также передаётся в onError. Два вызова `$next()` в одном middleware — ошибка разработчика.

Различайте две ситуации: цепочка дошла до конца без ответа — вызывается notFound; handler сам остановил её без Response, `setResponse()` и `$next()` — это ошибка, по умолчанию 500. Одинаковое правило действует и для единственного handler.

## Типовые статусы

Таблица описывает стандартную политику. Пользовательский `onError` может изменить ответ.

| Статус | Причина |
| --- | --- |
| 400 | Некорректный JSON или malformed percent encoding/UTF-8 в URI |
| 404 | Нет подходящего маршрута/метода; явный `$c->notFound()` |
| 413 | Превышен доступный для проверки предел тела или transport сообщил соответствующую ошибку |
| 414 | Превышен предел длины path; значение по умолчанию — 8192 bytes |
| 415 | Multipart поступил без подтверждённого разбора адаптером |
| 500 | Необработанный Throwable, ошибка middleware/финализации либо сбой пользовательского onError |

HEAD проходит через GET chain и обработку ошибок, затем получает ответ без тела. Статус и допустимые заголовки сохраняются. Не регистрируйте отдельный HEAD handler в расчёте на его выполнение: это не предусмотрено выбранным контрактом Hono v4.

## После начала отправки ответа

HTTP streaming/SSE реализованы. После отправки headers нельзя заменить статус и тело новым error response. Ошибку producer логируют и закрывают поток. Это отличается от обычной обработки handler до отправки ответа; подробности и статус поддержки — в [запуске приложения](deployment.md).

Далее: [Context и ответы](context-response.md), [middleware](middleware.md), [тестирование](testing.md).
