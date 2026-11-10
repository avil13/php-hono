# Переход от плана 0.1 к 1.0

Версия 0.1 существовала как проектный контракт; опубликованного пакета и данных для миграции нет. Финальное имя пакета — `php-hono/php-hono`, namespace — `PhpHono`. PHP ниже 8.5 не поддерживается.

Сопоставьте экспериментальный код с [API reference](api.md):

- Конфигурация передаётся named arguments в `new Hono(...)`; фабрики ответов — PSR-17.
- Front controller вызывает `Adapter\Sapi::run($app)`. Worker — `Adapter\FrankenPhpWorker::run($app)`.
- `HttpException($status, $message = '', $response = null, $previous = null)` содержит readonly `$status` и `$response`.
- Встроенные middleware — классы `PhpHono\Middleware`, которые создаются через `new`. PSR-15 подключается через `Psr15::wrap()`.
- HTML escaping — `Helper\Html::escape()`, cookies — `Helper\Cookie`, streaming — `Helper\Streaming`.
- `Context::extension(Type::class)` получает объект из того же точного ключа `set()`. Автоматического создания, поиска services и синхронизации PSR attributes нет.
- После `$next()` params возвращаются к параметрам вызывающего handler. Это выбранное отличие от upstream Hono.

После обновления зависимостей выполните `composer check` и реальные HTTP-тесты своего runtime. Для long-running worker перезапустите процессы после изменения приложения. Публикация release/tag выполняется отдельно от подготовки исходников.
