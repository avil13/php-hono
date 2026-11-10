# Context и ответы

Проектная документация API MVP для PHP 8.5+. Реализации пока нет; namespace `PhpHono` предварительный. [Оглавление](README.md).

`Context` создаётся заново для каждого запроса. Он объединяет `$c->req`, переменные запроса, состояние ответа и удобные фабрики `ResponseInterface`. Обработчики возвращают ответы; отправкой клиенту занимается [адаптер](deployment.md).

## Создание ответа

```php
use PhpHono\Context;
use PhpHono\Hono;
use Psr\Http\Message\ResponseInterface;

$app = new Hono();
$app->get('/', fn (Context $c): ResponseInterface => $c->text('Привет!'));
$app->get('/health', fn (Context $c): ResponseInterface => $c->json(['ok' => true]));
$app->get('/home', fn (Context $c): ResponseInterface => $c->redirect('/'));
```

| Метод | Назначение | Content-Type по умолчанию |
| --- | --- | --- |
| `text($value, $status = null, $headers = [])` | Текстовый ответ | `text/plain; charset=UTF-8` |
| `json($value, $status = null, $headers = [])` | Кодирование PHP-значения в JSON | `application/json` |
| `html($value, $status = null, $headers = [])` | Готовая HTML-строка | `text/html; charset=UTF-8` |
| `body($value, $status = null, $headers = [])` | Тело с явно выбранными headers | Задаётся приложением |
| `redirect($url, $status = 302)` | Перенаправление | Не является HTML renderer |
| `notFound()` | Ответ корневого notFound handler | Зависит от handler |

В примерах ниже предполагаются `$app` и импорты из первого блока. Для custom body указывайте формат явно:

```php
$app->get('/export', fn (Context $c): ResponseInterface =>
    $c->body("name\nphp-hono\n", 200, ['Content-Type' => 'text/csv; charset=UTF-8'])
);
```

Вызов фабрики не финализирует Context. Нужно вернуть полученный Response или установить его через `setResponse()`. Если handler не вернул ответ, не установил его и не вызвал `$next()`, framework обработает это как ошибку разработчика.

## Статус

Передайте статус вторым аргументом фабрики либо задайте его для последующих фабрик через `status()`:

```php
$app->post('/accepted', function (Context $c): ResponseInterface {
    $c->status(202);
    return $c->json(['accepted' => true]);
});
```

Явный статус фабрики имеет приоритет. Если статус не передан и `status()` не вызывался, используется 200. `status()` не меняет уже готовый Response. После `$next()` замените объект через `withStatus()` и `setResponse()`.

Для 204, 205 и 304 тело удаляется независимо от того, что передал handler. HEAD также возвращается без тела, но выполняет цепочку GET; подробнее в [маршрутах](routing.md).

## Заголовки

```php
$app->get('/report', function (Context $c): ResponseInterface {
    $c->header('Cache-Control', 'no-store');
    $c->header('X-Tag', 'summary');
    $c->header('X-Tag', 'daily', true);
    $c->header('X-Unused', null);

    return $c->json(['report' => 'daily']);
});
```

`header($name, $value)` заменяет значение, третий аргумент `true` добавляет значение, `null` удаляет заголовок. Имя сравнивается без учёта регистра. После `$next()` тот же метод меняет текущий ответ.

До первой установки Response заголовки накапливаются в Context. При установке они применяются один раз: обычные headers, явно заданные самим Response, имеют приоритет. `Set-Cookie` объединяется в порядке «подготовленные в Context → заданные в Response» отдельными строками. Затем очередь подготовленных headers очищается, поэтому удалённое значение не появляется повторно.

Несколько cookies можно задать отдельными значениями:

```php
$app->get('/preferences', function (Context $c): ResponseInterface {
    $c->header('Set-Cookie', [
        'theme=dark; Path=/; Secure; HttpOnly; SameSite=Lax',
        'language=ru; Path=/; Secure; HttpOnly; SameSite=Lax',
    ]);

    return $c->text('Настройки записаны');
});
```

Этот пример рассчитан на HTTPS из-за `Secure`. Для динамических значений предусмотрены cookie helpers; их точные сигнатуры ещё не закреплены. Не соединяйте `Set-Cookie` запятыми и не вставляйте непроверенные пользовательские данные в готовую строку cookie.

## Текущий ответ и явная замена

`response()` возвращает текущий Response или `null`, если его ещё нет. `setResponse()` явно устанавливает или заменяет ответ. Например, middleware может изменить статус после выполнения endpoint:

```php
$app->use('/async-report', function (Context $c, callable $next): void {
    $next();
    $response = $c->response();

    if ($response !== null && $response->getStatusCode() === 200) {
        $c->setResponse($response->withStatus(202));
    }
});
$app->get('/async-report', fn (Context $c): ResponseInterface =>
    $c->json(['queued' => true])
);
```

Простой `return $otherResponse` после `$next()` не заменяет уже финализированный ответ. Используйте `setResponse()` для замены и `header()` для изменения заголовков.

При замене действуют следующие правила:

1. Явно заданные headers нового ответа имеют приоритет.
2. Отсутствующие headers наследуются из прежнего ответа, кроме `Content-Type`, `Content-Length`, `Content-Encoding`, `Transfer-Encoding`, `ETag` и `Last-Modified`: они относятся к заменённому телу.
3. Явный новый список `Set-Cookie` заменяет весь старый. При отсутствии нового списка наследуется прежний. Повторная установка того же ответа не дублирует cookies.

Чтобы удалить header, используйте `$c->header('X-Name', null)`. Вызов `setResponse($response->withoutHeader('X-Name'))` может восстановить значение из предыдущего ответа согласно правилу наследования. У будущего PSR-15 bridge отдельная точная установка ответа, сохраняющая удаления PSR middleware.

## HTML и JSON

`html()` принимает готовую строку и не экранирует значения автоматически. Экранируйте текст, вставляемый в HTML:

```php
$app->get('/hello', function (Context $c): ResponseInterface {
    $name = $c->req->query('name') ?? 'гость';
    $escaped = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return $c->html('<h1>Привет, ' . $escaped . '!</h1>');
});
```

Это пример для текстового содержимого HTML. Встраивание значений в JavaScript, CSS или URL требует правил соответствующего контекста. JSX в проект не входит.

`json()` кодирует переданное PHP-значение. Например, `['ok' => true]` становится JSON object, а `['php', 'http']` — JSON array. Ошибка кодирования не превращается молча в пустой ответ: она проходит через [обработку ошибок](errors.md).

## Переменные запроса

Используйте `set/get` для передачи данных от middleware к endpoint:

```php
$app->use('/catalog/*', function (Context $c, callable $next): void {
    $c->set('locale', $c->req->query('locale') === 'en' ? 'en' : 'ru');
    $next();
});
$app->get('/catalog/items', fn (Context $c): ResponseInterface =>
    $c->json(['locale' => $c->get('locale')])
);
```

`get()` возвращает `null` и для отсутствующего ключа, и для явно сохранённого null. `has()` позволяет их различить. Значения Context не переносятся в следующий запрос; не сохраняйте сам Context в static/global или в долгоживущем сервисе.

Для объектов приложения в MVP предусмотрен `extension(Type::class): object` с generic PHPDoc. Сначала запишите объект через `set(Type::class, $object)`. Метод читает тот же точный ключ variables и проверяет `instanceof Type`; отсутствие, null или неверный тип дают `LogicException`, которая внутри dispatch обрабатывается существующим onError. Объект не создаётся и не кешируется отдельно. Полный контракт, интерфейсные ключи и примеры DI — в [расширении Context](context-extensions.md).

Bindings `env` также относятся к запросу и передаются через `fetch/request`. Синтаксис доступа к ним и конфигурации конструктора ещё не закреплён; руководство не вводит дополнительные свойства или методы поверх спецификации.

Далее: [middleware](middleware.md), [ошибки](errors.md), [совместимость](compatibility.md).
