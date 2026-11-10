# Задачи MVP / 0.1

21 задача, 31–55 человеко-дней без резерва. Статус реализации 10 ноября 2026: **implemented**, проверено локально. [Результаты и границы приёмки](../implementation-status.md). Оценки включают собственные проверки и документацию поведения. Общие требования: PHP 8.5, [контракт](../02-specification.md), [архитектура](../03-architecture.md), отсутствие runtime-зависимости от Node. T21 перенесена из 1.0 в обязательный MVP с сохранением ID и выполняется до T19. 2 ноября 2026 года T06 расширена типизированными extensions и примерами DI (+1 день по обеим границам), а верхняя оценка T01 увеличена на день для Mago и проверки staged snapshot.

## T01

**Основание Composer-пакета, строгие проверки и pre-commit/CI.** Этап M0. Оценка: **1–3 дня**. Зависимости: нет.

Объём: `composer.json`/lock, PSR-4, `src/tests` skeleton, PHPUnit, PHPStan `level: max`, **Mago 1.59.0** для format/lint, PHP 8.5 CI; зависимости PSR/Nyholm из архитектуры и dev `psr/container` для bootstrap fixture. Добавить MIT/attribution для переносимых upstream материалов. Установить рабочее package name для разработки, публикационное имя оставить явным решением перед релизом.

Подготовить `mago.toml` с target PHP 8.5, pin версии, default rules плюс обязательные strict-types/strict-behavior/no-eval/no-global/no-variable-variable; warnings и errors блокируют проверку. PHPStan остаётся единственным обязательным type analyzer, второй analyzer Mago не включать без отдельного решения. Определить source/include/exclude paths, узкие исключения для fixtures и запрет blanket baseline для нового кода. Точные команды, исходный профиль и источники — [разработка и проверки](../../docs/development.md).

Добавить Composer scripts format/format:check/lint/analyse/test/check:style/check и versioned `.githooks/pre-commit`, запускающий check:staged. Read-only hook проверяет изолированный снимок Git index вместе со staged конфигурацией, без изменения рабочих файлов/index, stash, autofix и сети. Installer hooks:install работает явно и локально, сохраняет существующие hooks. CI использует ту же версию и профиль, повторяет style/type/tests на commit и не зависит от локального hook.

Готово, когда: чистый checkout устанавливается на PHP 8.5; Composer validate/audit, один meaningful bootstrap smoke, Mago `fmt --check`/`lint`, PHPStan max и PHPUnit проходят в CI. Подтверждены Mago binary/version/config и PHP 8.5 syntax. Плохое форматирование и lint violation блокируют локальную команду, pre-commit и CI. Проверены partial staging в обе стороны, staged изменение config, rename/delete/пробелы в путях, сохранность index/working tree при успехе и ошибке, cleanup temp snapshot и отказ при отсутствующем инструменте. Versions/extensions воспроизводимы; runtime dependency tree зафиксировано и проверено. HTTP-функциональность в эту задачу не входит.

## T02

**Контракт совместимости и upstream fixtures.** Этап M0. Оценка: **2–3 дня**. Зависимости: T01.

Объём: manifest baseline `4.13.16 / c91b60a251186fb024557c6d55a11d8e388f179c`, JS harness, формат fixtures, PHP-side loader, classifications всех C-01–C-07 сценариев. Закрепить допустимые regex tokens и PCRE resource limits, path limit 8192 bytes, предел body buffer (предложение 8 MiB), header/status defaults, точные неподдерживаемые API. Включить license notice при переносе тестов.

`Context::extension()` классифицировать как PHP-specific дополнение C-04 с собственными PHP tests; не добавлять фиктивный эквивалент этого метода в JS upstream harness. Зафиксировать class/interface keys, LogicException и отсутствие автоматической синхронизации с PSR attributes.

Готово, когда: fixtures можно регенерировать на точном SHA; повторная генерация детерминирована; Node не нужен для чтения fixtures; supported/difference/unsupported различаются явно. Данные охватывают ordering, HEAD, query duplicates и response replacement. До реализации PHP runner допускается ожидаемо неполный, но не ложный зелёный отчёт о совместимости.

## T03

**HTTP messages и response factories.** Этап M1. Оценка: **1–2 дня**. Зависимости: T02.

Объём: PSR-7/17 integration, default Nyholm factory, явная injection alternative factories, builders text/json/html/body/redirect. Валидировать statuses, headers, Content-Type, JSON encoding failures; без своего PSR-7 message implementation.

Готово, когда: response tests проверяют status/bytes/headers, Unicode, object/array JSON, redirect и невалидные headers. Alternative PSR-17 factory проходит smoke. Helpers не отправляют output и не завершают процесс.

## T04

**Request: path, параметры, query и headers.** Этап M1. Оценка: **1–2 дня**. Зависимости: T03.

Объём: HonoRequest с raw PSR request; extraction API C-03; собственный query parser; route-scoped params; malformed encoding policy. Контракт path normalization использовать и в router/adapter.

Готово, когда: `x=1&x=2`, отсутствующее/пустое, `+`, UTF-8, literal dot/space/brackets, encoded slash и malformed input проходят выбранные fixtures. Headers case-insensitive; передача params одного middleware не раскрывает params следующего handler.

## T05

**Чтение тела, JSON и формы.** Этап M1. Оценка: **2–3 дня**. Зависимости: T04.

Объём: общий lazy body reader с ограничением, повторяемое text/json чтение, urlencoded parser, parsed multipart/uploads из PSR-7. Явные 400/413/415 и PHP JSON type rules; ownership non-seekable stream. Никакого raw multipart parser.

Готово, когда: повторные вызовы и разные body readers не теряют данные; ошибки JSON, urlencoded last/all/[], чтение без Content-Length и превышение буфера проверены. Подготовленный multipart с transport marker поддержан, включая пустую форму; raw multipart без parser и multipart `all=true` дают явный unsupported/error. Ограничения отражены в compatibility docs.

## T06

**Context, типизированные расширения и состояние Response.** Этап M1. Оценка: **3–4 дня**. Зависимости: T03, T04.

Объём: request-scoped env/variables/error, prepared headers/status, фабрики из T03, response()/setResponse(), финализация, escapeHtml helper. Закрепить поведение headers до ответа, после `$next()` и при замене.

Добавить `extension(string $type): object` с generic PHPDoc и runtime `instanceof` по C-04 поверх существующего `set/get` store. Примеры композиции AuthContext/TenantContext, constructor injection и прикладной request factory. Отдельного registry, autowiring или собственного контейнера не создавать.

Готово, когда: C-04 tests проверяют header priority/append/remove, cookie multiplicity, status defaults, explicit replacement, null vs missing variable и отсутствие протекания состояния. Сами response factories не финализируют Context.

Для extensions проверены class/interface key, точность ключа, идентичность объекта при повторном чтении, отсутствие constructor calls при чтении, замена через `set`, missing/null/scalar/wrong type → LogicException. PHPStan выводит конкретный тип из class-string. Общий `get/has` не меняет поведение; разные Context получают разные прикладные request-state objects при корректной инициализации.

## T07

**Ordered router и поддерживаемая grammar.** Этап M2. Оценка: **2–4 дня**. Зависимости: T02.

Объём: add/match, static/param/optional-terminal/wildcard/regex subset, методы и ALL, strict option, route metadata, freeze. Скомпилированные шаблоны плюс ordered scan; не портировать пять routers.

Готово, когда: все C-01 supported patterns проходят fixtures; match возвращает все нужные handlers в порядке регистрации с отдельными params. `/a/*` не захватывает `/ab`; unsupported patterns отвергаются при регистрации; поздняя регистрация отвергается; regex/path adversarial inputs имеют ограниченное поведение. PCRE resource errors диагностируются как ошибки, а не 404; длина path ограничена.

## T08

**Регистрация приложения и группы маршрутов.** Этап M2. Оценка: **1–2 дня**. Зависимости: T07.

Объём: public method registration, `on` с массивами методов/путей, `all/use`, `route/basePath`, child metadata для error handlers. Явные paths вместо implicit previous path API.

Готово, когда: nested prefixes, shared route table, snapshot child import и порядок middleware после группировки проверены. Child onError metadata сохраняется для dispatch; child notFound не подменяет root. Изменение frozen table через view невозможно.

Готовый invokable handler с constructor dependencies регистрируется обычным callable без resolver/container lookup при dispatch. Должны работать и явное создание объекта, и сборка в bootstrap через PSR-11 fixture; конкретный внешний контейнер не становится runtime-зависимостью ядра.

## T09

**Композиция middleware.** Этап M2. Оценка: **2–3 дня**. Зависимости: T06, T07.

Объём: синхронный pipeline C-02, next guard, route index, short-circuit, response finalization и injected error/notFound boundaries. Не добавлять отдельный single-handler fast path с другой семантикой.

Готово, когда: точные traces onion и short-circuit совпадают с fixtures; два next и void без next диагностируются; return после next и setResponse различаются по спецификации; обработанная downstream ошибка допускает upstream post-processing.

## T10

**Dispatch, HTTP exceptions, 404 и HEAD.** Этап M2. Оценка: **2–3 дня**. Зависимости: T05, T08, T09.

Объём: App handle/fetch, новый Context на каждый запрос, env, HttpException, root/child error priority, fallback 500, notFound, HEAD и bodyless statuses. App реализует PSR-15 RequestHandlerInterface.

Готово, когда: complete pipeline работает на готовых PSR requests; no-match даёт 404; HEAD выполняет GET chain с исходным методом HEAD, без body; 204/205/304 не содержат body. Ошибки до/после next и failing onError имеют предсказуемые ответы без утечки stack trace. Последовательные запросы одного App изолированы.

Проверить LogicException из `extension()` через обычный onError и из самого onError через fallback 500 без рекурсии. Request-scoped factory/initializer failures проходят те же правила; успешно созданные объекты запроса не удерживаются framework между вызовами.

## T11

**SAPI request adapter и emitter.** Этап M3. Оценка: **2–3 дня**. Зависимости: T10.

Объём: создание PSR request из globals через готовую библиотеку, raw-target checks, multipart parsing marker, files/upload errors и выявление post_max_size overflow при доступном размере; отправка статуса/headers/stream. FPM и CLI server bootstrap, trust proxy policy, отсутствие `exit` в библиотеке.

Готово, когда: HTTP integration tests на PHP 8.5 FPM и CLI server проверяют JSON, формы/uploads, 413 для выявленного превышения, HEAD и несколько Set-Cookie. Host/proxy headers не меняют trusted scheme/IP без настройки. Headers-already-sent диагностируется; streams и temporary uploads освобождаются согласно ownership.

## T12

**Вызов приложения в памяти.** Этап M3. Оценка: **1–2 дня**. Зависимости: T10.

Объём: `request(path|url|ServerRequest, options, env)` поверх PSR-17 и dispatch, fixture runner для PHP; base URL `http://localhost` для относительных путей. В options поддержать только документированные method/headers/body.

Готово, когда: examples/tests получают Response без сети; query/body/env проходят тот же pipeline; вызов с абсолютным URL не делает исходящий запрос. Unsupported options отклоняются. Fixtures показывают настоящий pass/fail и список deliberate differences.

## T13

**Validator middleware.** Этап M3. Оценка: **1–2 дня**. Зависимости: T05, T09, T12, T15.

Объём: targets json/form/query/param/header/cookie, callback, ранний Response и validated data в Request. Cookie extraction переиспользовать из T15. Не добавлять свою schema library.

Готово, когда: успешно нормализованные значения доступны через valid(target), невалидный ввод останавливает handler, malformed body сохраняет HTTP error. Вызов valid без соответствующей validation — ошибка. Последовательные запросы не разделяют validated data.

## T14

**Body limit middleware.** Этап M3. Оценка: **1–2 дня**. Зависимости: T05, T11, T12.

Объём: route-specific lower limit через общий reader, Content-Length precheck плюс bounded pre-read actual bytes до next с общим кешем, 413/custom error response; порядок до parsers. Описать границу между framework limit и SAPI/server limit.

Готово, когда: fixed/missing/ложная длина, non-seekable stream, handler без чтения тела и чтение несколькими middleware проверены. Oversized raw body отклоняется до endpoint с чтением не более limit+1 bytes. Для SAPI multipart тесты/документация не заявляют контроль до PHP buffering и обнаружение отсутствующих transport diagnostics.

## T15

**Cookie helpers.** Этап M4. Оценка: **1–2 дня**. Зависимости: T06, T12.

Объём: get/set/delete cookies, атрибуты, escaping/validation, multiple Set-Cookie, reuse parser для validator. Signed/encrypted cookies отложены.

Готово, когда: round-trip обычных значений, malformed Cookie, атрибуты и удаление с тем же Path/Domain проверены; Set-Cookie не склеивается. SameSite=None без Secure и header injection отклоняются. Cookie helpers работают до и после next.

## T16

**CORS middleware.** Этап M4. Оценка: **1–2 дня**. Зависимости: T09, T12.

Объём: origin allow-list/callback, methods/headers, credentials, expose/max-age, preflight, Vary, ошибки downstream.

Готово, когда: разрешённые/отклонённые origins, OPTIONS preflight и обычные/error responses проходят контракт; Vary объединяется с существующим; wildcard с credentials отвергается. Отсутствие Origin не создаёт ложного CORS разрешения.

## T17

**Secure headers middleware.** Этап M4. Оценка: **1–2 дня**. Зависимости: T09, T12.

Объём: документированный набор безопасных HTTP headers, override/disable options, явная CSP policy, применение к normal/notFound/error responses.

Готово, когда: default и overrides проверены; middleware не теряет existing multi-value headers; unsupported options не игнорируются. Документация перечисляет значения и объясняет, какие настройки приложения нельзя угадать автоматически.

## T18

**Logger и request ID.** Этап M4. Оценка: **1–2 дня**. Зависимости: T09, T12.

Объём: configurable logging callback, method/path/status/duration/request-id; валидация входного id и криптографическая генерация нового; monotonic clock для duration.

Готово, когда: normal/404/500 логируются, invalid/oversized id заменяется, id доступен в Context и response. По умолчанию не логируются query values, body, credentials и cookies. Ошибка logging sink не изменяет уже подготовленный HTTP-ответ и имеет документированную диагностику.

## T21

**PSR-15 middleware bridge и сторонние пакеты.** Этап M3. Оценка: **2–3 дня**. Зависимости: T10, T12.

Объём: `PhpHono\Middleware\Psr15::wrap(MiddlewareInterface): callable`, адаптация стороннего объекта в Hono pipeline без изменения его кода, forwarding request attributes/headers/body, точная установка Response внутренней операцией без наследования headers. Запрет неявного rematch при смене URI/method. Пример подключения готового Composer middleware и инструкция для внешнего PSR pipeline перед routing.

Добавить пример и integration test явного mapper `PSR attribute → Context extension` после соответствующего middleware. Проверить тип значения и политику отсутствия/удаления атрибута, отсутствие автоматической обратной синхронизации, сохранение Context variables при восстановлении upstream raw request. Mapper — прикладной callable, не новая обязательная подсистема bridge.

Готово, когда: минимум два реальных пакета — middlewares/uuid и middlewares/response-time — установлены в dev dependencies и проходят integration tests на PHP 8.5; выбранные версии записаны в отчёт. Дополнительные contract tests проверяют short-circuit, attributes/parsed body, замену body, удаление обычных headers/Set-Cookie, normal/404/500/HEAD и смешанный порядок Hono/PSR middleware. Цепочка Hono reader → PSR getBody() → endpoint text/json() сохраняет bytes даже для non-seekable источника: bridge предоставляет replayable body из общего ограниченного кеша. Кеш сохраняется при том же подготовленном stream и инвалидируется при замене; лимиты применяются повторно. Request view восстанавливается после возврата, повторный downstream вызов и URI rewrite дают документированную ошибку. Ограничение error-boundary описано; отдельная проверка внешнего pre-routing middleware показывает, что App получает изменённый запрос. MVP без этого результата не выпускается.

## T19

**Приёмка совместимости MVP.** Этап M5. Оценка: **2–4 дня**. Зависимости: T11, T12, T13, T14, T15, T16, T17, T18, T21.

Объём: integrated C-01–C-07 fixture suite, runtime tests, проверка response merging и смешанного Hono/PSR middleware order, интеграции сторонних пакетов из T21 и сочетание bodyLimit → PSR getBody() → endpoint; targeted adversarial parser tests. Закрыть найденные расхождения либо оформить явное изменение спецификации.

Готово, когда: все compatible fixtures проходят; каждый deliberate difference имеет тест и объяснение; unsupported не попадают в процент успеха. На PHP 8.5 CI проходят analysis/unit/contract/FPM checks; повторные запросы не разделяют состояние. Сохраняется воспроизводимый отчёт проверки.

В приёмку включить typed extensions, сборку invokable handlers через constructor injection/PSR-11 fixture и последовательные requests с разными пользователями, ранним ответом и ошибкой; Context state и mutable данные request factory не протекают через повторно используемый handler.

## T20

**Документация и пакет MVP 0.1.** Этап M5. Оценка: **1–2 дня**. Зависимости: T19.

Объём: README, quickstart, routing/middleware/errors guide, JSON API и forms examples, FPM config, testing guide, compatibility matrix и changelog. Руководство context-extensions объясняет typed state, constructor injection, необязательный PSR-11 bootstrap и ownership request-scoped ресурсов. Уточнить namespace/package name, проверить лицензию и содержимое Composer archive.

Готово, когда: новый пользователь может установить пакет и повторить примеры на PHP 8.5; ограничения route grammar/multipart понятны без чтения исходников; dev tooling/upstream checkout не попадают в production runtime. Release candidate 0.1 готов к публикации; внешнюю публикацию выполнять отдельным действием при переходе от планирования к выпуску.
