# Производительность: сравнение архитектур и предложения

Дата исследования: **1 ноября 2026 года**. Целевая платформа php-hono: **PHP 8.5+**.

Статус: **рекомендации к плану, не принятые изменения контрактов**. Сопоставлены исходники других проектов с [архитектурой](03-architecture.md), [roadmap](04-roadmap.md) и задачами php-hono. Реализации php-hono ещё нет; нагрузочные тесты не выполнялись. Ни рейтинг скорости, ни численное ускорение из этого исследования не следуют.

## Вывод

Сохранить PSR-7/17 как общую HTTP-модель и обязательный PSR-15 bridge. Основные кандидаты на ускорение — **индексы маршрутов вместо полного перебора, отсутствие чтения body при ненужном доступе к bridge, подготовка неизменяемых данных при freeze и повторное использование приложения в worker**. Первые измерения следует перенести с конца плана на появление рабочего dispatch.

Для небольшого приложения простой OrderedRouter может оказаться выгоднее сложного компилятора с учётом bootstrap. Для большого приложения или постоянного процесса баланс может быть другим. Выбирать алгоритм следует по этим двум профилям отдельно.

## Что исследовано

Зафиксированы конкретные снимки исходников. Указанный SHA не означает, что соответствующий HEAD — выбранный стабильный релиз для будущего benchmark. Перед замерами нужны совместимые релизы, Composer lock и проверка на PHP 8.5.

| Проект / компонент | Исследованная версия или снимок |
| --- | --- |
| Slim | `App::VERSION = 4.15.1`, [`3675bf6baac66b07032575b7bef4200b60b7974b`](https://github.com/slimphp/Slim/tree/3675bf6baac66b07032575b7bef4200b60b7974b) |
| FastRoute, используемый Slim | **v1.3.0**, [`181d480e08d9476e61381e04a71b34dc0432e812`](https://github.com/nikic/FastRoute/tree/181d480e08d9476e61381e04a71b34dc0432e812); исследована ветка зависимости Slim `^1.3`, не FastRoute `2.0-dev` |
| Mezzio | [`74334a4ed27b82457b2ab1f0e6b56ca287180b35`](https://github.com/mezzio/mezzio/tree/74334a4ed27b82457b2ab1f0e6b56ca287180b35), вместе с документацией Mezzio v3 |
| Laminas Stratigility | [`b41b50ad47d79ef2f3b9337f7297c459c7468dcb`](https://github.com/laminas/laminas-stratigility/tree/b41b50ad47d79ef2f3b9337f7297c459c7468dcb) |
| Mezzio Router / FastRoute adapter | [`707b89f5ea30a2847395405ccfa793de4a373758`](https://github.com/mezzio/mezzio-router/tree/707b89f5ea30a2847395405ccfa793de4a373758) / [`e6056555b1be8433cffaa59594240f36f06fd0e0`](https://github.com/mezzio/mezzio-fastroute/tree/e6056555b1be8433cffaa59594240f36f06fd0e0) |
| Flight | **v3.19.5**, [`b9d3bdcffe186a753bd6a7f8a4e0434480a67c5c`](https://github.com/flightphp/core/tree/b9d3bdcffe186a753bd6a7f8a4e0434480a67c5c) |
| Fat-Free core | **3.9.3-Release**, [`01f53dc3f6d65fa221c45719debe048f29963c54`](https://github.com/bcosca/fatfree-core/tree/01f53dc3f6d65fa221c45719debe048f29963c54) |
| Phalcon Micro | Официальная документация **5.23**: [Micro](https://docs.phalcon.io/5.23/application-micro/), [router](https://docs.phalcon.io/5.23/routing/), [архитектура](https://docs.phalcon.io/5.23/introduction/). Здесь исследование документации, без локального аудита C/Zephir-реализации |

Это **пять фреймворков**. FastRoute — библиотека маршрутизации; Stratigility — pipeline; FrankenPHP — runtime. Их нельзя считать дополнительными микрофреймворками или смешивать их стоимость со стоимостью всего приложения.

## Сравнение с нашим планом

| Проект | Подтверждённое устройство | Сопоставление с php-hono |
| --- | --- | --- |
| **Slim + FastRoute** | PSR-7/15. Exact routes ищутся в таблице по method/path; dynamic routes — через объединённые regex по чанкам. Выбирается один маршрут. Есть cache routing metadata. Wrappers для переданных middleware objects строятся при `add()`. | Наиболее близкий PSR baseline. Стоит заимствовать индексацию и подготовку, сохранив наш ordered multi-match. Сам bridge не обязан читать body. |
| **Mezzio + Stratigility** | Нативная PSR-15 цепочка; отдельно routing и dispatch. Нормализация middleware при построении pipeline; возможна ленивая загрузка через контейнер. Тело pipeline не читает. | Подтверждает возможность PSR-совместимости без конвертации HTTP-моделей и eager body parsing. Универсальный контейнер и дополнительное разделение dispatch нам не требуются. |
| **Flight** | Собственные HTTP objects и middleware. Индекс по method, но union/sort кандидатов выполняется при подборе; затем последовательные проверки. Exact fast path находится внутри отдельного Route. | Полезен как компактный userland baseline. Подготовку шаблонов и порядка кандидатов можно вынести из request path. Его middleware API не заменяет PSR-15. |
| **Fat-Free** | Центральный Base/hive; сортировка routes по специфичности и последовательное сопоставление. Собственные controller hooks. Route TTL кеширует ответы. | Компактность интересна, но глобальное request state, specificity order и response cache нельзя переносить как эквивалент Hono dispatch. |
| **Phalcon Micro 5.23** | Framework в PHP extension; собственные HTTP/middleware API и DI. Router выбирает первое совпадение при обратном просмотре routes; возможна ленивая загрузка handler. | Отдельная категория benchmark. Заимствуем ленивое создание тяжёлых сервисов; extension bootstrap и другую семантику маршрутов userland-порт автоматически не воспроизведёт. |

Источники и важные детали:

- **Slim/FastRoute:** [static/dynamic dispatcher](https://github.com/nikic/FastRoute/blob/181d480e08d9476e61381e04a71b34dc0432e812/src/Dispatcher/RegexBasedAbstract.php#L20-L86), [regex chunks](https://github.com/nikic/FastRoute/blob/181d480e08d9476e61381e04a71b34dc0432e812/src/DataGenerator/GroupCountBased.php), [Slim dispatcher/cache](https://github.com/slimphp/Slim/blob/3675bf6baac66b07032575b7bef4200b60b7974b/Slim/Routing/Dispatcher.php), [middleware wrappers](https://github.com/slimphp/Slim/blob/3675bf6baac66b07032575b7bef4200b60b7974b/Slim/MiddlewareDispatcher.php#L68-L133). Slim использует LIFO middleware order; php-hono должен оставить Hono order. Строковые middleware и route callable могут разрешаться при dispatch: это не полностью скомпилированное приложение. [Body parser](https://github.com/slimphp/Slim/blob/3675bf6baac66b07032575b7bef4200b60b7974b/Slim/Middleware/BodyParsingMiddleware.php) подключается отдельно.
- **Mezzio:** [MiddlewareFactory](https://github.com/mezzio/mezzio/blob/74334a4ed27b82457b2ab1f0e6b56ca287180b35/src/MiddlewareFactory.php), [lazy middleware](https://github.com/mezzio/mezzio/blob/74334a4ed27b82457b2ab1f0e6b56ca287180b35/src/Middleware/LazyLoadingMiddleware.php), [Next](https://github.com/laminas/laminas-stratigility/blob/b41b50ad47d79ef2f3b9337f7297c459c7468dcb/src/Next.php), [routing](https://github.com/mezzio/mezzio-router/blob/707b89f5ea30a2847395405ccfa793de4a373758/src/Middleware/RouteMiddleware.php). Stratigility клонирует очередь на запуск и служебный Next на переход. Lazy wrapper сам не кеширует результат container lookup. В исследованном [FastRoute adapter](https://github.com/mezzio/mezzio-fastroute/blob/e6056555b1be8433cffaa59594240f36f06fd0e0/src/FastRouteRouter.php) после matcher есть проход routes для восстановления объекта маршрута. Поэтому стоимость adapter не равна одному hash lookup. [Body parsing](https://docs.mezzio.dev/mezzio/v3/features/helpers/body-params/) рекомендуется подключать только там, где он нужен.
- **Flight:** [Router](https://github.com/flightphp/core/blob/b9d3bdcffe186a753bd6a7f8a4e0434480a67c5c/flight/net/Router.php#L246), [Route::matchUrl](https://github.com/flightphp/core/blob/b9d3bdcffe186a753bd6a7f8a4e0434480a67c5c/flight/net/Route.php#L105), [Engine](https://github.com/flightphp/core/blob/b9d3bdcffe186a753bd6a7f8a4e0434480a67c5c/flight/Engine.php#L560), [middleware](https://docs.flightphp.com/en/v3/learn/middleware). Handler может вернуть true и продолжить поиск routes; это не Hono onion contract. `_start()` сбрасывает Request/Response/router при повторном запуске, поэтому описывать Flight как исключительно FPM-фреймворк неверно.
- **Fat-Free:** [route registration / mask / run](https://github.com/bcosca/fatfree-core/blob/01f53dc3f6d65fa221c45719debe048f29963c54/base.php#L1604), [controller hooks](https://github.com/bcosca/fatfree-core/blob/01f53dc3f6d65fa221c45719debe048f29963c54/base.php#L2055). Regex формируется при проверке; TTL — cache результата GET/HEAD, а не compiled routing table.
- **Phalcon:** [middleware contract](https://docs.phalcon.io/5.23/application-micro/#middleware), [lazy handlers](https://docs.phalcon.io/5.23/application-micro/#lazy-loading), [routing precedence](https://docs.phalcon.io/5.23/routing/#routing). Вывод об extension относится к исследованной ветке 5.x. Загрузка framework как расширения не означает сохранение пользовательского App между запросами.

## 1. Индексировать кандидатов, сохранив семантику Hono

**Сейчас:** T07 предусматривает OrderedRouter с O(N) просмотром таблицы. Шаблоны уже готовятся заранее — повторно предлагать это как отсутствующую возможность не нужно.

**Предложение:** после freeze иметь method buckets, exact-path lookup и небольшой индекс по заведомо буквальному начальному сегменту. Списки содержат registration IDs, а не копии route objects. Сложные, optional и wildcard patterns, для которых нельзя доказать принадлежность сегменту, остаются в общем fallback bucket. Фильтр может оставить лишнего кандидата, но не вправе потерять совпадение.

Начать с method + exact + dynamic lists; индекс сегмента добавлять только при измеренной пользе. Все списки сохраняют порядок регистрации. При запросе объединять подходящие уже упорядоченные списки по ID и проверять динамические кандидаты, вместо сортировки всей route table. Не создавать заранее комбинации для каждого возможного URL; память индекса должна зависеть от объявленных routes.

В варианте с первым сегментом нужны максимум шесть списков: exact/prefix/fallback для routing method (GET при HEAD по нашему профилю) и `ALL`. Каждая регистрация относится к одной категории; metadata хранится один раз. Обход по курсорам позволяет объединять списки за O(C) при C кандидатах, без `array_merge()`/`sort()` и без копирования общих middleware в каждый bucket. Ключ сегмента извлекается из той же формы path, которую использует окончательный matcher; при неоднозначности шаблон идёт в fallback. Размещение всех routes под `/api` может свести пользу такого индекса к минимуму — углублять его заранее не нужно.

Например, для `GET /users/me` могут подходить все три регистрации:

```text
1. use('/users/*', auth)
2. get('/users/:id', handlerA)
3. get('/users/me', handlerB)
```

Exact lookup не имеет права сразу вернуть `handlerB`: нужны `auth`, `handlerA`, `handlerB` в таком порядке, с собственными params каждого совпадения. После match их исполнение зависит от `next()` и short-circuit. [FastRoute возвращает одного winner и запрещает часть duplicate/shadowed registrations](https://github.com/nikic/FastRoute/blob/181d480e08d9476e61381e04a71b34dc0432e812/src/DataGenerator/RegexBasedAbstract.php#L81-L119); поэтому он не является прямой заменой нашего RouterInterface.

Не обещать O(1) для полного dispatch. Работа зависит от числа кандидатов и совпадений; если все N routes — подходящие wildcard, полный результат сам требует O(N). Прототип сравнивать с OrderedRouter на 10/100/1000 routes и оставлять простую реализацию, если выигрыш не покрывает стоимость подготовки/памяти.

**Приёмка:** differential tests возвращают одинаковые ordered IDs и params для overlaps, duplicates, optional/regex/wildcard, encoded slash, HEAD и miss. Сохраняются 404 по умолчанию, strict slash и исходный method в Request. OrderedRouter остаётся эталоном; пользовательский выбор из пяти разных router engines пока не нужен.

## 2. Убрать ненужное чтение body на границе PSR-15

**Сейчас:** раздел bridge требует получить bytes через bounded cache до передачи request стороннему middleware. Это платная операция даже для `uuid`, `response-time` или раннего отказа по заголовкам. PSR-15 её не требует: [MiddlewareInterface](https://www.php-fig.org/psr/psr-15/) определяет передачу PSR request и handler, а не стратегию чтения тела.

**Предложение:** сохранить единый request-scoped body store, но материализовать bytes только при первой операции, которой они нужны. Начать с простого варианта: ограниченное полное чтение при первом чтении stream; инкрементальный replay/spooling добавлять лишь при доказанной пользе. Если тело уже есть в Hono cache, использовать его; не создавать заново полный string и stream на каждом переходе Hono → PSR → Hono.

Проверенный seekable источник потенциально допускает rewind без полной копии, но один `isSeekable()` не решает вопросы лимита, writable body и cursor после downstream. Для non-seekable источника нужен replay buffer. [Guzzle CachingStream](https://github.com/guzzle/psr7/blob/cdc139396207a5eba10c242d241fb014cb098390/src/CachingStream.php) демонстрирует lazy replay, но **не является готовым bounded body store** нашего приложения: лимиты, decoded cache и владение ресурсами нужно согласовать отдельно. Это пример устройства, а не решение добавить ещё одну runtime-зависимость.

Это **изменение контракта**, а не незаметная внутренняя оптимизация. Сейчас bridge может обнаружить превышение лимита до вызова header-only middleware; после изменения неиспользованное тело не вычитывается. `bodyLimit()` сохраняет существующую eager проверку до `$next()` и 413 даже при endpoint без чтения body. Без него предел lazy cache проверяется при чтении. Transport/SAPI могут прочитать запрос раньше framework — отсутствие чтения в bridge не означает нулевой сетевой I/O.

Нужно закрыть ещё один вопрос текущего плана: **тот же stream object не гарантирует те же bytes**. PSR-7 streams изменяемы; внешний middleware может записать данные в тот же объект. Предпочтительный простой вариант — framework-owned read-only replay stream и изменение тела через `withBody()`. PSR-7 разрешает read-only streams и рекомендует их для server requests. Если требуется поддерживать in-place `write()` стороннего middleware, понадобится отслеживание изменения bytes и инвалидация decoded cache; нельзя молча выдавать старый JSON. Это следует явно закрепить в профиле совместимости, а не объявлять совместимость с любым PSR-15 пакетом. [PSR-7, streams](https://www.php-fig.org/psr/psr-7/#13-streams).

**Приёмка до включения оптимизации:**

- Header-only цепочка без `bodyLimit()` и без чтения downstream читает **0 bytes из source stream**; ранний 401 также не запускает body reader.
- Hono reader → PSR reader → Hono reader возвращает одинаковые bytes. Отдельно PSR middleware первым частично читает non-seekable источник, после чего downstream Hono text/json получает полное тело. Новое `withBody()` и Content-Type корректно меняют кеши.
- `read/getContents/__toString`, `seek/tell/eof/getSize`, `close/detach` имеют согласованные PSR semantics. Превышение лимита или ошибка stream не превращаются незаметно в успешный ответ с пустым/усечённым телом; отдельно решить error propagation для `__toString()`, где PSR-7 запрещает бросать исключение. Проверить PSR-only middleware, которое приводит избыточное тело к строке и возвращает Response без downstream: отсутствие вызова Hono reader не должно скрывать ошибку чтения.
- `bodyLimit()` по-прежнему отклоняет избыточное тело без доверия к одному Content-Length. Не возникает полного дублирования body buffer на каждой границе middleware.
- Read-only поведение проверено реальным body-reading пакетом дополнительно к `middlewares/uuid` и `middlewares/response-time`; для middleware, требующего writable body, либо реализован и проверен контракт записи, либо опубликовано конкретное ограничение.

Если lazy-контракт не удастся сделать простым и проверяемым, первым вариантом остаётся eager bounded буферизация **с исправленной mutability/invalidation policy**. Ошибка кеширования по одному object identity относится и к исходному подходу; её нельзя откладывать вместе с оптимизацией. Стоимость eager-варианта публикуется в benchmark. Ускорение не должно ухудшить надёжность сторонних middleware.

## 3. Подготавливать pipeline один раз, состояние запроса хранить отдельно

Заимствовать у Slim/Mezzio подготовку форм вызова, а не их порядок или все уровни абстракции. При регистрации/freeze нормализовать callables и создать неизменяемые bridge descriptors. Во время запроса нужны только match result, Context, текущий request view и состояние продолжения цепочки.

Начальная реализация — массив handlers и request-scoped index/continuations. Сравнить её стоимость с вложенными wrappers; не вводить очередь и клонирование только ради повторения Stratigility. Нельзя хранить Context, params, request или single-use guard в общем объекте продолжения, используемом несколькими запросами. Для разных match results последовательность всё равно выбирается на запросе; заранее готовится неизменяемая часть, а не все возможные цепочки URL.

Hono callable получает Context напрямую. PSR middleware получает тот же PSR-7 request через `process($request, $handler)`; не нужно превращать каждый native handler в полноценный PSR middleware. Wrapper `Psr15::wrap()` остаётся публичным входом. Reflection, service discovery, event bus и container resolution не добавляются в обязательный request path.

**Приёмка:** unchanged onion trace, single-use next, short-circuit, exceptions до/после next, явная замена Response, request view restoration и отдельные params. Возвращённый PSR Response устанавливается точно: `withoutHeader()` не должен отменяться наследованием Context headers. Single-handler fast path допустим только с теми же semantics и измеренным выигрышем.

## 4. Сохранить один HTTP-слой и ленивые операции Context

PSR-7 уже является границей App и моделью raw request/response. Это хороший выбор для поддержки сторонних пакетов. Введение собственного «быстрого» HTTP-слоя с преобразованием в PSR на каждой границе создаст новые конвертации и два набора правил headers/body.

Оставить Nyholm реализацией по умолчанию и явно передаваемые PSR-17 factories. Не объявлять её самой быстрой без отдельного замера; даже [создание stream из строки](https://github.com/Nyholm/psr7/blob/889d890ba25e4264ad92ce56b18ef8218611ca51/src/Stream.php#L77-L100) имеет разные пути для разных размеров body. Измерять готовое приложение, а не количество классов библиотеки.

Лениво разбирать query/body/cookies и создавать Response только когда он требуется. Pending headers до финализации собирать вместе, где это позволяет текущий Context contract, сокращая лишние immutable copies. После передачи Response стороннему коду соблюдать PSR immutability и правила многозначных headers/Set-Cookie. Не переиспользовать один mutable body stream в ответах разных запросов.

Эти предложения уточняют уже выбранную архитектуру; собственная реализация PSR-7, обязательный DI и pooling request objects не нужны.

## 5. Раньше проверить постоянный процесс; cache для FPM оценивать отдельно

[FrankenPHP worker](https://frankenphp.dev/docs/worker/) позволяет загрузить приложение один раз и обрабатывать несколько запросов. Для этого у нас уже правильно разделены frozen configuration и request state. Стоит проверить такой цикл после рабочего core/bridge, не дожидаясь полного набора HTTP extensions. Полный production adapter, cleanup и restart policy остаются отдельным результатом T29.

Это потенциально более крупный рычаг, чем уменьшение нескольких вызовов compose, **но размер эффекта неизвестен**. Сравнивать FPM и worker как разные deployment profiles, а конкурентов — на сопоставимых runtime. Нельзя объявить преимущество framework, сравнив только php-hono worker с чужим cold bootstrap.

В worker тестировать также сторонние middleware: повторно используемый объект может хранить request-specific данные независимо от корректности нашего Context. Нужны чередующиеся пользователи/ошибки/short-circuit, освобождение streams и retained-memory test. Stateful зависимости должны иметь подходящий lifecycle; один PSR-15 interface не гарантирует worker safety.

Для FPM возможен build-time cache scalar routing metadata с привязкой к версии приложения и routing options. Не сериализовать closures, Context и middleware instances. Cache не отменяет весь bootstrap; его чтение тоже стоит времени. Начать без файлового cache и добавлять, только если registration/compile заметны в замере. Не создавать неограниченный cache по входящим URL.

OPcache включать в production baseline. [Preloading](https://www.php.net/manual/en/opcache.preloading.php), JIT и число workers измерять отдельно: preloading не сохраняет произвольное mutable приложение между FPM-запросами, а [настройки FrankenPHP](https://frankenphp.dev/docs/performance/) зависят от нагрузки и оборудования.

## 6. Сохранить реальные границы PSR-15 совместимости

Внутренний bridge исполняется **после routing**. Он поддерживает request attributes, headers, parsed body, replacement body и post-processing Response. Rewrite URI/method размещается во внешнем PSR-15 pipeline перед App. Retry с повторным downstream вызовом остаётся несовместимым с Hono single-use next. Эти ограничения уже есть в плане, а не вводятся ради оптимизации.

Endpoint exception превращается Hono error boundary в Response; PSR error middleware не увидит уже обработанное исключение. Внешний error middleware нужен для ошибок собственного внешнего pipeline, но не меняет это поведение App. Нельзя обещать drop-in совместимость любого стороннего error/retry middleware только на основании интерфейса.

Оставить T21 обязательной для MVP и проверять реальные пакеты. В performance suite отдельно измерять native Hono, PSR-15 и смешанную цепочки; выигрыш, полученный отключением bridge или проверок корректности, не отвечает задаче проекта.

## Предлагаемые изменения порядка работ

| Приоритет | Изменение | Связь с существующими задачами |
| --- | --- | --- |
| **P0** | Ранний benchmark harness и baseline при появлении dispatch | Разделить работу T30: первая часть после T10/T12, затем дополнить PSR после T21 и worker после T29; не ждать T20/T29 для всех измерений |
| **P0** | Закрепить lazy bridge body, пределы и mutability policy до реализации bridge | Совместно уточнить T02/T05/T14/T21; синхронно обновить spec и docs, если предложение принимается |
| **P1** | Прототип method/exact candidate index против OrderedRouter | T07 + ранняя часть T30; acceptance — одинаковый результат и измеренная польза |
| **P1** | Нормализация handlers при freeze, request-local continuation | T08/T09/T21; проверить стоимость native/PSR/mixed pipeline |
| **P1** | Ранний worker smoke/profiling после core и bridge | Исследовательская часть T29 после T10/T11/T21; полноценная приёмка adapter остаётся в T29 |
| **P1** | Перед MVP зафиксировать FPM/in-process результаты с PSR-15 | Добавить результаты раннего benchmark к T19/T20, затем расширить в T31/T32 |
| **P2** | Только по профилю: prefix index, compiled metadata cache, более сложный replay stream | Отдельно оценить после прототипов; не прятать их в исходных 2–4 днях T30 |

Это предложение к существующим **32 задачам**, а не уже принятые изменения backlog ради производительности. На дату исследования исходные оценки были **30–53 дня MVP / 47–85 до 1.0**; актуальные оценки после других согласованных изменений находятся в [roadmap](04-roadmap.md). Они не покрывают автоматически все новые варианты оптимизации. В частности, bounded replay stream и сложный router могут потребовать выделения новых задач. Сначала получить baseline и выбрать реализацию, затем уточнить объём; число новых задач сейчас было бы предположением.

При принятии затронуть согласованно `02-specification.md`, `03-architecture.md`, `04-roadmap.md`, карточки T02/T05/T07/T09/T14/T19/T21/T29/T30 и руководства по middleware/request/deployment/compatibility. Этот исследовательский документ сам не меняет их обещания.

## Как проверить, что стало быстрее

### Сопоставимые участники

- Основные PSR baselines: **Slim + FastRoute** и **Mezzio + FastRoute**, по возможности одинаковая PSR-7 реализация, одинаковые сторонние middleware и ответы.
- Дополнительные userland baselines: **Flight** и **Fat-Free**, с явно отмеченным собственным HTTP/middleware API. Для них native middleware benchmark не доказывает стоимость PSR-15.
- **Phalcon Micro 5.x** — отдельный extension track, только если выбранная версия реально доступна на PHP 8.5. Не понижать PHP одного участника ради строки в общей таблице.
- FastRoute можно измерять отдельно как matcher, помня о single-winner contract. Для Hono overlaps эталон — наш OrderedRouter; чужому router не приписывается функциональная эквивалентность.

### Нагрузки и уровни измерения

| Уровень | Сценарии |
| --- | --- |
| Bootstrap | Composer/autoload, создание App, регистрация/freeze; cache включён/выключен отдельно |
| Router | 10/100/1000 routes; static, parameter, wildcard, overlapping matches, ранний/поздний hit, miss; одинаковые seeds |
| In-process dispatch | Text/JSON без I/O; 0/1/5/10 middleware; native, PSR и смешанные цепочки; HEAD, 404 и error path |
| Body / bridge | POST 1 KiB / 64 KiB / 1 MiB; seekable/non-seekable; header-only, early 401, parser, body replacement, повторное чтение, превышение лимита |
| HTTP | PHP-FPM отдельно от worker; одинаковые response bytes/status/headers и набор включённых проверок; несколько уровней concurrency |
| Worker lifecycle | Длительная последовательность запросов, смена пользователей, ошибки и short-circuit; retained memory и закрытие ресурсов |

Для body paths инструментировать число прочитанных bytes и полных буферов, но итоговые timings снимать без тяжёлого профайлера. Ответы проверять до сравнения скорости: ошибочный 500 или пропущенное middleware не являются ускорением. Отдельные response-cache результаты не смешивать с uncached dispatch.

Фиксировать CPU/OS, PHP 8.5 patch/build, SAPI/server/runtime, Composer lock, OPcache/JIT, число процессов/threads, concurrency, warmup, длительность и число повторов. Повторять в перемешанном порядке; публиковать разброс, throughput, p50/p95/p99, error rate, peak и retained memory. Разделять warm OPcache с новым App, первый запрос worker с freeze и уже прогретый worker dispatch.

Оптимизация принимается, когда сохраняются contract/integration tests, выигрыш воспроизводится за пределами шума, а влияние на bootstrap, memory и другие сценарии видно в отчёте. Если результаты неоднозначны, оставить более простую реализацию. Корректная цель — малая измеренная стоимость Hono semantics и PSR-15 bridge на выбранных нагрузках; обещание «быстрее всех PHP-фреймворков» пока не обосновано.
