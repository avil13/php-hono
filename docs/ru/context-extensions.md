# Расширение Context и зависимости

[English](../en/context-extensions.md) | **Русский**

API php-hono 1.0 для PHP 8.5. [Оглавление](overview.md).

Состояние текущего запроса хранится в обычных объектах приложения. Middleware создаёт объект, записывает его в Context через `set()`, а handler получает его с проверкой типа через `extension()`. Это композиция: наследовать Context для добавления собственных полей не требуется.

## Типизированное состояние запроса

```php
use PhpHono\Context;
use PhpHono\Hono;
use Psr\Http\Message\ResponseInterface;

final readonly class AuthContext
{
    public function __construct(public ?string $userId) {}
}

$app = new Hono();
$app->use('*', function (Context $c, callable $next): void {
    // Демонстрационный анонимный пользователь, не реализация аутентификации.
    $c->set(AuthContext::class, new AuthContext(userId: null));
    $next();
});
$app->get('/me', function (Context $c): ResponseInterface {
    $auth = $c->extension(AuthContext::class);

    if ($auth->userId === null) {
        return $c->json(['error' => 'Требуется аутентификация'], 401);
    }

    return $c->json(['id' => $auth->userId]);
});
```

`AuthContext` — класс приложения. В реальном auth middleware идентификатор берётся из результата проверенной аутентификации. Не выдавайте непроверенные query/header значения за подтверждённую личность.

Middleware-поставщик должен выполняться до handler-потребителя. Иначе `extension()` обнаружит отсутствие объекта и выбросит `LogicException`. Внутри dispatch она передаётся в существующий `onError`; ошибка из самого `onError` обрабатывается существующим fallback 500. Вне dispatch это обычное исключение вызывающему коду. Ошибка настройки зависимостей отличается от нормального состояния анонимного пользователя: для него объект существует с `userId === null`.

## Контракт extension

Сигнатура реализованного метода [Context::extension](https://github.com/avil13/php-hono/blob/master/src/Context.php) с PHPDoc:

```text
/**
 * @template T of object
 * @param class-string<T> $type
 * @return T
 */
public function extension(string $type): object;
```

PHP проверяет возвращаемый `object`, а generic PHPDoc позволяет статическому анализатору вывести конкретный тип из `AuthContext::class`. Это PHPDoc-контракт, не native generics PHP. [Описание generics в PHPStan](https://phpstan.org/blog/generics-in-php-using-phpdocs).

Правила метода:

- Читается **тот же точный case-sensitive ключ** `$type` в variables, который используют `set/get/has`. Отдельного хранилища extensions нет.
- Значение должно быть объектом и удовлетворять `instanceof $type`. Отсутствие ключа, null, скаляр и объект другого типа дают `LogicException`.
- Возвращается тот же экземпляр. Метод не создаёт и не клонирует объект, не ищет сервис и не запускает autowiring.
- Повторный `set($type, $other)` меняет результат следующего `extension($type)`: отдельного memoization cache нет.
- Имена PHP-классов могут разрешаться языком без учёта регистра, но ключи Context остаются точными строками. Используйте `SomeType::class` последовательно, не меняя регистр вручную.

`set/get/has` сохраняют прежнее поведение: они принимают произвольные значения и не вводят такую проверку типа. Например, `set(AuthContext::class, null)` допустим; последующий `get()` вернёт null, `has()` — true, а `extension()` выбросит исключение.

Интерфейс регистрируется явно. Если `AuthContext` реализует ваш `Principal`, запишите объект под `Principal::class`:

```php
// $auth реализует интерфейс Principal, объявленный в приложении.
$c->set(Principal::class, $auth);
$principal = $c->extension(Principal::class);
```

Запись только под `AuthContext::class` не делает его доступным по `Principal::class`. Запись под двумя ключами разрешена, но замена значения одного ключа не меняет другой автоматически.

## Constructor DI для handlers и middleware

Создавайте готовые callable-объекты при bootstrap. Зависимости передавайте обычным конструктором; Context предоставляет им данные текущего запроса.

Пример использует `AuthContext`, `$app` и импорты первого блока. `AuthLookup` и классы ниже принадлежат приложению:

```php
use Psr\Http\Message\ServerRequestInterface;

interface AuthLookup
{
    public function userId(ServerRequestInterface $request): ?string;
}

final readonly class AttachAuth
{
    public function __construct(private AuthLookup $lookup) {}

    public function __invoke(Context $c, callable $next): void
    {
        $auth = new AuthContext($this->lookup->userId($c->req->raw()));
        $c->set(AuthContext::class, $auth);
        $next();
    }
}

final readonly class WhoAmI
{
    public function __construct(private string $applicationName) {}

    public function __invoke(Context $c): ResponseInterface
    {
        $auth = $c->extension(AuthContext::class);
        return $c->json([
            'application' => $this->applicationName,
            'userId' => $auth->userId,
        ]);
    }
}

/** @var AuthLookup $lookup Реализация из bootstrap приложения. */
$app->use('/api/*', new AttachAuth($lookup));
$app->get('/api/me', new WhoAmI('Example API'));
```

В этом примере `AuthLookup` допустимо разделять между запросами только при подходящем lifecycle: он не должен сохранять текущего пользователя в своём состоянии. Сам `AuthContext` создаётся внутри `__invoke()` заново. `readonly` у middleware запрещает замену ссылки, но не делает автоматически неизменяемым переданный ему сервис.

## Необязательный контейнер в bootstrap

Приложение может использовать любой подходящий [PSR-11 контейнер](https://www.php-fig.org/psr/psr-11/) для сборки своих объектов. Это решение приложения: ядру php-hono не нужна runtime-зависимость `psr/container`.

```php
/** @var \Psr\Container\ContainerInterface $container */
$authMiddleware = $container->get(AttachAuth::class);
if (!$authMiddleware instanceof AttachAuth) {
    throw new \LogicException('Контейнер должен вернуть AttachAuth');
}

$app->use('/api/*', $authMiddleware);
$app->get('/api/me', new WhoAmI('Example API'));
```

Это альтернативная регистрация к предыдущему блоку. Контейнер используется при bootstrap и не передаётся в Context для произвольного поиска сервисов во время запроса. Он возвращает готовый callable; `use/get` не разрешают class strings через контейнер автоматически.

`Context` — final-класс. В API нет `App::container()`, resolver class strings, `contextFactory/stateFactory`, `__get` и macros. Используйте composition, constructor DI и явные вызовы `set/extension`.

## Lifecycle и освобождение ресурсов

В FPM каждый запрос и в постоянном worker каждый вызов App получают новый Context. Однако зарегистрированный handler и захваченные им сервисы могут быть общими. Для состояния одного запроса вызывайте factory **внутри** middleware/handler. Factory должна возвращать новый объект запроса, а не кешировать его как singleton.

Ниже отдельный пример приложения для управления незавершённой транзакцией; `$openConnection` — factory, возвращающая отдельное PDO-соединение с подходящей конфигурацией. Lifecycle middleware регистрируется до всех endpoint, которым нужно это соединение:

```php
/** @var \Closure(): \PDO $openConnection */
$lifecycleApp = new Hono();
$lifecycleApp->use('/api/*', function (Context $c, callable $next) use ($openConnection): void {
    $db = $openConnection();
    $c->set(\PDO::class, $db);

    try {
        $next();
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
});
// Нужные endpoint регистрируются в $lifecycleApp после middleware.
```

Успешную транзакцию явно завершает сервис, который знает результат операции. Не делайте безусловный commit после `$next()`: downstream exception уже мог превратиться в Response через `onError`. Даже HTTP-статус сам по себе не доказывает, что бизнес-операция успешно завершена. `finally` закрывает незавершённую работу; явное освобождение других ресурсов выполняется через API соответствующего сервиса.

Объект, сохранённый в Context, остаётся доступен до окончания запроса. Не храните Context или request-объекты в static/global, singleton или в захваченной ссылке между запросами. При тестировании повторно вызывайте **один App**, чтобы обнаружить утечки состояния.

## PSR-15 attributes и typed extension

PSR request attributes и variables Context — разные хранилища. Автоматической синхронизации нет. После PSR middleware поставьте явный mapper, который читает **текущий forwarded request** через `$c->req->raw()`:

```php
use PhpHono\Middleware\Psr15;

/** @var \Psr\Http\Server\MiddlewareInterface $authentication */
$psrApp = new Hono();
$psrApp->use('/api/*', Psr15::wrap($authentication));
$psrApp->use('/api/*', function (Context $c, callable $next): void {
    $auth = $c->req->raw()->getAttribute(AuthContext::class);
    if (!$auth instanceof AuthContext) {
        // Повторный mapper не должен оставлять прежний AuthContext.
        $c->set(AuthContext::class, null);
        throw new \LogicException('Auth middleware должен предоставить AuthContext');
    }

    $c->set(AuthContext::class, $auth);
    $next();
});
$psrApp->get('/api/me', new WhoAmI('Example API'));
```

Пример предполагает договорённость приложения: `$authentication` передаёт объект `AuthContext` как PSR attribute под этим именем. Если библиотека использует другой ключ или тип, mapper преобразует его явно. При отсутствии или удалении attribute сначала сбросьте прежнее значение через `set(AuthContext::class, null)`, затем сообщите ошибку контракта либо верните 401 для обычного неавторизованного запроса. Очистка обязательна в обеих ветках: ранний 401 не должен оставлять прежнюю identity доступной upstream middleware. Нельзя молча сохранять ранее записанный AuthContext при повторном mapper в одном запросе. Это альтернативный путь к `AttachAuth`, а не второй обязательный auth middleware.

При выходе из bridge upstream middleware снова видит свой raw request. Variables Context остаются общими для этого запроса: значение, записанное mapper-ом, не откатывается вместе с request view. Порядок PSR middleware → mapper → потребитель обязателен. При short-circuit до mapper он не выполняется, а ранее установленные variables сохраняются; ранний ответ сам по себе не очищает Context.

## Запускаемый пример

[examples/context-di.php](https://github.com/avil13/php-hono/blob/master/examples/context-di.php) возвращает готовый `Hono`: `AttachTenant` получает request factory через конструктор, `HelloTenant` получает конфигурацию через конструктор, а явный mapper переносит `RequestMetadata` из PSR attribute в extension. Классы примера находятся в [examples/ContextDi](https://github.com/avil13/php-hono/tree/master/examples/ContextDi); внешний контейнер не нужен. Tenant задан доверенной конфигурацией bootstrap, а request ID создаётся заново для каждого вызова.

Из корня проекта после установки Composer dependencies:

```sh
php -r '$app = require "examples/context-di.php"; echo $app->request("/whoami")->getBody();'
```

Ответ содержит `message: "Hello, tenant example"` и случайный `requestId`. При втором вызове того же App идентификатор будет другим. Для HTTP-запуска используйте возвращаемое приложение с [SAPI adapter](deployment.md).

Далее: [middleware](middleware.md), [Context и ответы](context-response.md), [тестирование](testing.md), [запуск](deployment.md).
