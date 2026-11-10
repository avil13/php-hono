---
layout: home
title: HTTP в стиле Hono для PHP 8.5
hero:
  name: php-hono
  text: HTTP в стиле Hono для PHP 8.5
  tagline: Создавайте HTTP API с упорядоченной маршрутизацией, отдельным Context для каждого запроса и сторонними PSR-15 middleware.
  actions:
    - theme: brand
      text: Начать
      link: /ru/getting-started
    - theme: alt
      text: Справочник API
      link: /ru/api
    - theme: alt
      text: Read in English
      link: /en/
features:
  - title: Небольшой и понятный API
    details: Регистрируйте маршруты по порядку, объединяйте middleware и возвращайте PSR-7 ответы из обработчиков.
    link: /ru/routing
  - title: Используйте готовые middleware
    details: Подключайте сторонние PSR-15 middleware и передавайте зависимости через конструкторы.
    link: /ru/middleware
  - title: Выбирайте способ запуска
    details: Тестируйте запросы в памяти, обслуживайте их через SAPI/FPM или используйте FrankenPHP worker с HTTP streaming и SSE.
    link: /ru/deployment
---

```php
use PhpHono\Context;
use PhpHono\Hono;

$app = new Hono();
$app->get('/hello/:name', fn (Context $c) =>
    $c->json(['hello' => $c->req->param('name')])
);

$response = $app->request('/hello/Ada');
echo $response->getBody(); // {"hello":"Ada"}
```

Самостоятельная реализация, вдохновлённая [Hono](https://github.com/honojs/hono). Выбранный профиль 1.0 реализован; Composer-пакет ещё не опубликован. См. [установку](getting-started.md) и [совместимость](compatibility.md).
