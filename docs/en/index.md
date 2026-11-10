---
layout: home
title: Hono-style HTTP for PHP 8.5
hero:
  name: php-hono
  text: Hono-style HTTP for PHP 8.5
  tagline: Build HTTP APIs with ordered routing, a request-scoped Context, and third-party PSR-15 middleware.
  actions:
    - theme: brand
      text: Get started
      link: /en/getting-started
    - theme: alt
      text: API reference
      link: /en/api
    - theme: alt
      text: Читать на русском
      link: /ru/
features:
  - title: A small, explicit API
    details: Register routes in order, compose middleware, and return PSR-7 responses from your handlers.
    link: /en/routing
  - title: Bring your middleware
    details: Connect third-party PSR-15 middleware and inject dependencies through constructors.
    link: /en/middleware
  - title: Choose how to run
    details: Test requests in memory, serve them with SAPI/FPM, or use the FrankenPHP worker adapter with HTTP streaming and SSE.
    link: /en/deployment
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

An independent implementation inspired by [Hono](https://github.com/honojs/hono). The selected 1.0 feature set is implemented; the Composer package has not yet been published. See [installation](getting-started.md) and [compatibility](compatibility.md).
