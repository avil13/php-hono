# Разработка и проверки

[Оглавление](README.md) · PHP 8.5+. Конфигурация и команды находятся в репозитории; Git hook подключается явно для каждого checkout.

## Инструменты

**Mago** используется для форматирования и lint; **PHPStan** — для проверки типов на уровне `max`; **PHPUnit** — для тестов. Mago analyzer пока не становится вторым обязательным анализатором типов. Это сохраняет отдельную проверку PHPDoc generics, callable signatures и PSR contracts в PHPStan. [Уровни PHPStan](https://phpstan.org/user-guide/rule-levels).

Проект использует Mago **1.59.0**, исследован стабильный тег [`95a25d686a2b584c387326e466d6ead8c885465d`](https://github.com/carthage-software/mago/tree/95a25d686a2b584c387326e466d6ead8c885465d). В исходниках есть целевая версия PHP 8.5; конфигурация и smoke-сценарии проверяются на PHP 8.5. Это инструменты разработки, не зависимости production runtime. [Поддерживаемые PHP versions](https://github.com/carthage-software/mago/blob/95a25d686a2b584c387326e466d6ead8c885465d/crates/php-version/src/lib.rs).

Для установки зависимостей, тестов и приложения нужны PHP 8.5+ и Composer. Локальный hook вызывает `php tools/quality.php staged` напрямую: `php` должен быть доступен в PATH процесса Git, включая GUI-клиент, а Composer во время коммита не требуется. Mago анализирует синтаксис целевого PHP 8.5 независимо от runtime quality wrapper. Поддержанные платформы локального quality wrapper: macOS/Linux x86_64 и aarch64; CI использует Linux.

Подготовка окружения:

```sh
composer install
composer tools:prepare
composer check
composer hooks:install
```

Версия фиксируется точным constraint и Composer lock. Composer wrapper может скачать подходящий бинарник при первом запуске; выполнить этот шаг при настройке среды/CI, до работы pre-commit. В hook нет установки зависимостей и сетевого скачивания: отсутствие подготовленного инструмента блокирует проверку с инструкцией настройки. [Установка Mago](https://github.com/carthage-software/mago/blob/95a25d686a2b584c387326e466d6ead8c885465d/docs/content/en/guide/installation.md).

## Строгая конфигурация

`mago.toml` хранится в репозитории. Действующий профиль:

```toml
#:schema https://mago.carthage.software/1.59.0/schema.json
version = "1.59.0"
version-drift-fail-level = "patch"
php-version = "8.5"

[source]
paths = ["src", "tests", "examples", "benchmarks", "tools"]
includes = ["vendor"]
excludes = ["build", "coverage", "var"]

[source.glob]
literal-separator = true

[parser]
enable-short-tags = false

[linter]
minimum-fail-level = "warning"

[linter.rules]
strict-types = { enabled = true, level = "error" }
strict-behavior = { enabled = true, level = "error" }
no-eval = { enabled = true, level = "error" }
no-global = { enabled = true, level = "error" }
no-variable-variable = { enabled = true, level = "error" }
```

Это default rules выбранной версии плюс явные обязательные правила проекта. Ошибки и предупреждения блокируют проверку. `strict-types` обеспечивает `declare(strict_types=1)` в PHP-файлах; исключения для намеренно некорректных тестовых fixtures задаются адресно с объяснением. Не создавать общий baseline, который скрывает нарушения нового кода. Подавление конкретного правила должно быть локальным и обоснованным.

`version-drift-fail-level = "patch"` запрещает незаметную смену версии инструмента; одной настройки `version` для такого поведения недостаточно. Обновление Mago — отдельное согласованное изменение pin/lock/config с проверкой новых диагностик. [Проверка версии](https://github.com/carthage-software/mago/blob/95a25d686a2b584c387326e466d6ead8c885465d/src/version_check.rs).

Список `paths` покрывает исходники, тесты, примеры, benchmarks и PHP tools. При добавлении каталогов проверяйте охват. `vendor` используется как источник определений, но не форматируется и не lint-ится как код проекта. Исключения генерируемых файлов и fixtures задаются явно; не полагаться только на `.gitignore`. [Source configuration](https://github.com/carthage-software/mago/blob/95a25d686a2b584c387326e466d6ead8c885465d/docs/content/en/guide/configuration.md).

Перед фиксацией профиля проверить фактические файлы и правила:

```sh
vendor/bin/mago list-files --command formatter
vendor/bin/mago list-files --command linter
vendor/bin/mago lint --list-rules --json
vendor/bin/mago lint --explain strict-types
```

`--pedantic` не включается автоматически: он активирует все правила, включая неподходящие проекту, и игнорирует ограничения правил по версии PHP. Строгий профиль должен быть проверенным набором требований. [Lint configuration](https://github.com/carthage-software/mago/blob/95a25d686a2b584c387326e466d6ead8c885465d/docs/content/en/tools/linter/configuration-reference.md).

## Команды проекта

Composer scripts:

| Команда | Действие |
| --- | --- |
| `composer format` | Ручное форматирование через `mago fmt`; меняет рабочие файлы |
| `composer format:check` | `mago fmt --check`, без записи |
| `composer lint` | `mago lint`, без `--fix` |
| `composer analyse` | PHPStan с репозиторной конфигурацией `level: max` |
| `composer test` | PHPUnit |
| `composer check:style` | Format check, затем lint; ненулевой exit code любого шага сохраняется |
| `composer check` | Style, type analysis и tests |
| `composer check:staged` | Style-проверка снимка Git index по правилам ниже |
| `composer hooks:install` | Локальное подключение проверяемого `.githooks/pre-commit` |

CI устанавливает lockfile на PHP 8.5, проверяет версию Mago, выполняет Composer validate/audit, `composer check` и интеграционные сценарии соответствующего этапа. Проверки используют одну репозиторную конфигурацию. Autofix в CI и pre-commit не выполняется.

## Проверка перед коммитом

Versioned `.githooks/pre-commit` вызывает `php tools/quality.php staged` — ту же точку входа, что и `composer check:staged`. Проверяется **подготовленное к коммиту содержимое**, включая staged конфигурацию, а не только одноимённые рабочие файлы. Для этого `tools/quality.php` экспортирует raw Git blobs во временный снимок index и запускает на нём `mago fmt --check` и `mago lint` с подготовленным инструментом и удаляет снимок после проверки. В снимке проверяется весь configured PHP source set, чтобы изменения правил не пропустили нарушения в неизменённых файлах.

Workspace и config передаются явно: `mago --workspace "$staged_snapshot" --config "$staged_snapshot/mago.toml" fmt --check` и тот же prefix для `lint`. Здесь `$staged_snapshot` — путь временного снимка, а `mago` — заранее подготовленный бинарник закреплённой версии. Отсутствие staged `mago.toml` — ошибка, без fallback к пользовательскому config. Hook исключает внешние overrides вроде `MAGO_NO_VERSION_CHECK` и `MAGO_PHP_VERSION`, которые могли бы отменить pin или PHP target. CI также использует явно выбранную репозиторную конфигурацию.

Hook не меняет index/working tree, не выполняет `git add`, `stash` или autofix. Он сохраняет partial staging и корректно учитывает добавленные, удалённые и переименованные файлы; пути с пробелами не разбиваются shell-ом. Конфигурация проверяется из того же снимка. Внешние определения из установленного `vendor` допустимы как зависимости, но не должны подменять staged код проекта. При ошибке подготовки снимка, отсутствии инструмента или несовпадении pin проверка завершается ошибкой. Неразрешённые конфликты, symlink и submodule в staged дереве отклоняются явно: snapshot поддерживает обычные файлы и executable bit. Checkout filters не запускаются; проверяются исходные bytes Git blobs.

Локальный установленный `vendor` не обязательно соответствует staged изменению `composer.lock`: hook проверяет staged PHP-код с доступными определениями зависимостей, но не удостоверяет новый dependency graph. Проверка установки и всего набора проверок по закоммиченному lockfile обязательна в CI; hook сам зависимости не переустанавливает. Pin самого Mago должен совпадать со staged конфигурацией и lockfile, иначе локальная проверка завершается ошибкой.

В Mago 1.59.0 `fmt --check --staged` несовместимы, а `fmt --staged` изменяет index. У `lint --staged` staged используется для отбора путей, затем читается working tree. Поэтому эти флаги не заменяют описанную проверку при partial staging. [Formatter flags](https://github.com/carthage-software/mago/blob/95a25d686a2b584c387326e466d6ead8c885465d/src/commands/format.rs), [lint file selection](https://github.com/carthage-software/mago/blob/95a25d686a2b584c387326e466d6ead8c885465d/src/commands/lint.rs).

Установка hook выполняется явно командой `composer hooks:install`, без автоматической перезаписи пользовательской Git-конфигурации при `composer install`. Installer проверяет существующий `core.hooksPath` и hooks; при конфликте сохраняет их и выводит инструкцию объединения. При свободной конфигурации задаёт локальный `.githooks` и проверяет executable bit. Глобальные настройки Git не меняются.

Локальный hook можно обойти средствами Git, поэтому CI обязательно повторяет проверки на самом commit. Полные type/integration tests не запускаются внутри pre-commit; они доступны через `composer check` и выполняются в CI.

## Регрессионные проверки toolchain

- На PHP 8.5 Mago принимает конфигурацию, корректный код проходит, а намеренно плохое форматирование и lint violation дают ненулевой exit code в локальной команде, hook и CI.
- `strict_types`, PHP 8.5 syntax и PHPStan generic accessor проверены минимальными сценариями; типизация самого `Context::extension()` дополнительно проверяется в T06.
- Partial staging проверен в обе стороны: плохой index / исправленный working tree блокирует коммит; корректный index / неготовый working tree оценивается по index.
- Изменение `mago.toml`, rename/delete и пути с пробелами учитываются; после успеха и ошибки staged/unstaged содержимое остаётся побайтно прежним, временные файлы удалены.
- Существующие hooks сохраняются; отсутствующий бинарник не приводит к успешному пропуску проверки или сетевой установке из hook.

Тесты `tests/Tooling` создают временные Git repositories и проверяют эти сценарии без изменения текущего index или Git hooks. Основной CI запускает `composer check`; отдельная runtime matrix выполняет [FPM/FrankenPHP HTTP-проверки](deployment.md#воспроизводимая-проверка-транспорта). Сторонний swew/test не входит в обязательный toolchain; тестовый API проекта — PHPUnit.

Далее: [тестирование](testing.md), [план T01](../spec/tasks/01-mvp.md#t01), [архитектура проверок](../spec/03-architecture.md).

## Проверка архива библиотеки

```sh
php tools/release/check-package.php
```

Команда требует PHP 8.5, актуальный Composer 2 и `unzip`; при необходимости путь к Composer PHAR задаётся переменной `COMPOSER_BINARY`. Проверенная локальная версия — Composer 2.11.2. Старый Composer 2.8.9 несовместим с PHP 8.5 в сценарии загрузки локального ZIP; системную установку можно оставить без изменений и передать отдельный PHAR.

Проверка создаёт `build/php-hono-1.0.0.zip`, проверяет состав архива, устанавливает его вместе с production dependencies в отдельный временный consumer и выполняет routing/JSON/PSR-15 smoke. `vendor`, кеши, build, tests, tools и конфигурация разработки исключены через `.gitattributes` и `composer.json` archive settings. Исходники, документация, примеры и лицензия остаются в пакете. Результат сохраняется в `build/package-check.json`, временный consumer удаляется.

Версия `1.0.0` назначается только записи локального package repository для проверки неопубликованного ZIP; команда не создаёт Git tag и не публикует пакет. Перед выпуском повторите её после финальных изменений. CI также проверяет ограничение памяти генератора командой `php benchmarks/stream-memory.php`.
