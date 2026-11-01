# Исследование Hono

## Основание

Локально прочитаны код и тесты Hono 4.13.16 из коммита [`c91b60a251186fb024557c6d55a11d8e388f179c`](https://github.com/honojs/hono/tree/c91b60a251186fb024557c6d55a11d8e388f179c), полученного 1 ноября 2026 года. Upstream-тесты не запускались; выводы ниже основаны на чтении. Проверка поведения исполнением запланирована в T02 и T19.

[Документация миграции](https://hono.dev/docs/guides/migrating-to-v5) уже описывает v5 RC, включая другой контракт `onError` и вынос runtime adapters. Поэтому при конфликте источников приоритет имеют код и тесты указанного SHA. Обновление baseline — отдельное осознанное изменение спецификации.

## Что именно представляет собой Hono

Hono объединяет HTTP-приложение, router, цепочку обработчиков и Context. JavaScript Request/Response и Web API позволяют адаптировать его к нескольким runtimes. TypeScript inference, JSX, клиент RPC и runtime helpers — дополнительные подсистемы, а не обязательные части минимального HTTP-ядра. [README](https://github.com/honojs/hono/blob/c91b60a251186fb024557c6d55a11d8e388f179c/README.md), [exports](https://github.com/honojs/hono/blob/c91b60a251186fb024557c6d55a11d8e388f179c/package.json).

| Область | Проверенный источник | Вывод для порта |
| --- | --- | --- |
| Приложение | [hono-base.ts](https://github.com/honojs/hono/blob/c91b60a251186fb024557c6d55a11d8e388f179c/src/hono-base.ts), [hono.test.ts](https://github.com/honojs/hono/blob/c91b60a251186fb024557c6d55a11d8e388f179c/src/hono.test.ts) | Регистрация, группировка, dispatch, HEAD, 404 и ошибки — основа контракта |
| Router | [hono.ts](https://github.com/honojs/hono/blob/c91b60a251186fb024557c6d55a11d8e388f179c/src/hono.ts), [router.ts](https://github.com/honojs/hono/blob/c91b60a251186fb024557c6d55a11d8e388f179c/src/router.ts), [общие тесты](https://github.com/honojs/hono/blob/c91b60a251186fb024557c6d55a11d8e388f179c/src/router/common.case.test.ts) | Возвращается цепочка совпадений с параметрами каждого обработчика |
| Middleware | [compose.ts](https://github.com/honojs/hono/blob/c91b60a251186fb024557c6d55a11d8e388f179c/src/compose.ts), [compose.test.ts](https://github.com/honojs/hono/blob/c91b60a251186fb024557c6d55a11d8e388f179c/src/compose.test.ts) | Onion-порядок, ранний ответ, запрет повторного next, финализация ответа |
| Context | [context.ts](https://github.com/honojs/hono/blob/c91b60a251186fb024557c6d55a11d8e388f179c/src/context.ts), [context.test.ts](https://github.com/honojs/hono/blob/c91b60a251186fb024557c6d55a11d8e388f179c/src/context.test.ts) | Состояние запроса, фабрики ответов, заголовки до и после downstream |
| Request | [request.ts](https://github.com/honojs/hono/blob/c91b60a251186fb024557c6d55a11d8e388f179c/src/request.ts), [request.test.ts](https://github.com/honojs/hono/blob/c91b60a251186fb024557c6d55a11d8e388f179c/src/request.test.ts) | Доступ к параметрам, query, headers, кешу тела и результатам validation |
| URL и формы | [url.ts](https://github.com/honojs/hono/blob/c91b60a251186fb024557c6d55a11d8e388f179c/src/utils/url.ts), [body.ts](https://github.com/honojs/hono/blob/c91b60a251186fb024557c6d55a11d8e388f179c/src/utils/body.ts) | Нельзя без проверки подменять PHP `parse_str` и полным декодированием пути |
| Исключения | [http-exception.ts](https://github.com/honojs/hono/blob/c91b60a251186fb024557c6d55a11d8e388f179c/src/http-exception.ts) | HTTP-ошибка может нести готовый Response |
| Экосистема | [middleware](https://github.com/honojs/hono/tree/c91b60a251186fb024557c6d55a11d8e388f179c/src/middleware), [helpers](https://github.com/honojs/hono/tree/c91b60a251186fb024557c6d55a11d8e388f179c/src/helper), [adapters](https://github.com/honojs/hono/tree/c91b60a251186fb024557c6d55a11d8e388f179c/adapters) | Требует отбора по полезности и совместимости с PHP runtime |

## Семантика, которую легко потерять при переносе

1. Маршруты и middleware идут в порядке регистрации. Статический маршрут не вытесняет параметрический автоматически; несколько совпадений образуют цепочку.
2. `route(prefix, child)` импортирует уже зарегистрированные маршруты. Поздние изменения child не добавляют маршруты родителю. `basePath()` разделяет таблицу маршрутов с исходным приложением.
3. В v4 дочерний `onError` сохраняется при группировке, а `notFound` берётся у корневого приложения.
4. HEAD диспетчеризуется как GET с сохранением исходного метода в Request; наружу возвращается ответ без тела. Отдельно зарегистрированный HEAD не выбирается этим механизмом.
5. После `next()` простой возврат другого Response в общем compose-пути не заменяет уже финализированный ответ; нужна явная замена в Context.
6. Повторный `next()` — ошибка. Downstream exception преобразуется в ответ, после чего upstream middleware может продолжить работу.
7. `query('x')` берёт первое значение, `queries('x')` — все. У form parsing по умолчанию последнее значение. `foo[]` в Hono остаётся именем поля, а не превращается автоматически в PHP `foo`.
8. `Set-Cookie` необходимо сохранять отдельными значениями. Request-scoped переменные и кеш тела нельзя переносить между запросами worker.
9. Автоматического 405 в ядре нет: это отдельный [method-not-allowed middleware](https://github.com/honojs/hono/blob/c91b60a251186fb024557c6d55a11d8e388f179c/src/middleware/method-not-allowed/index.ts).
10. У upstream отличается обработка отсутствующего Response в оптимизированном пути одного handler и общей цепочке. PHP-порт вводит единое правило и явно фиксирует это различие.

Пункты 1–10 подтверждаются исходниками и тестами из таблицы; целевой PHP-контракт и границы приведены в [спецификации](02-specification.md).

## Что переносится иначе

| Возможность upstream | Решение для PHP |
| --- | --- |
| Fetch Request/Response | PSR-7 плюс удобная оболочка Request и Context; браузерный Fetch API не эмулировать |
| SmartRouter: RegExpRouter + TrieRouter; другие presets | Один ordered router в MVP, сравнение алгоритмов после измерений |
| Async middleware / Promise | Синхронный вызов `$next()` с тем же порядком до/после |
| Runtime execution context / waitUntil | Не обещать эквивалент в PHP-FPM |
| Context Storage | Явно передавать Context; upstream использует [AsyncLocalStorage](https://github.com/honojs/hono/blob/c91b60a251186fb024557c6d55a11d8e388f179c/src/middleware/context-storage/index.ts) |
| Timeout | Лимиты сервера; [Promise.race в upstream](https://github.com/honojs/hono/blob/c91b60a251186fb024557c6d55a11d8e388f179c/src/middleware/timeout/index.ts) не эквивалентен отмене блокирующего PHP handler |
| Streaming / SSE | Отдельный emitter и producer contract; ограничения после отправки headers |
| WebSocket | Исключён из проекта по решению пользователя; не переносить и не включать в backlog |
| Cache API / proxy | Отдельные адаптеры; не добавлять скрытый HTTP-клиент и cache store в ядро |
| JSX / DOM / CSS / SSG | JSX исключён пользователем; связанную JSX/DOM подсистему не переносить. `html(string)` достаточно для HTTP-ответа; независимый SSG — отдельная идея |
| Typed RPC / hc | PHPDoc/статический анализ не заменяют TypeScript inference; codegen — отдельное направление |

## Лицензия и статус

Upstream распространяется по [MIT](https://github.com/honojs/hono/blob/c91b60a251186fb024557c6d55a11d8e388f179c/LICENSE). План предусматривает MIT для проекта и сохранение upstream copyright/permission notice при переносе существенного кода или тестов. Не использовать формулировку «официальный Hono для PHP» без оснований.

Рекомендации по PHP и оценка задач — проектные выводы этого исследования. Производительность порта, поддержка PHP-зависимостями версии 8.5 и фактическая совместимость пока не проверены запуском.
