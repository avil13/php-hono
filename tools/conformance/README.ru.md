# Эталонные сценарии закреплённой версии Hono

[English](README.md) | **Русский**

PHP-тесты читают сохранённые в репозитории fixtures без сети. Node и checkout upstream нужны только для их регенерации. Baseline — Hono **4.13.16**, commit `c91b60a251186fb024557c6d55a11d8e388f179c`.

С Node 24 или новее, поддерживающим нативное удаление TypeScript-типов, выполните из корня проекта:

```sh
git clone --depth 1 --branch v4.13.16 https://github.com/honojs/hono.git /tmp/php-hono-upstream
node --experimental-loader ./tools/conformance/resolve-typescript.mjs ./tools/conformance/generate.mjs /tmp/php-hono-upstream
```

Перед импортом upstream генератор проверяет HEAD, версию пакета и отсутствие изменений отслеживаемых исходников. Он выполняет сценарии приложения из `scenarios.json`; `http.json` содержит реальные status, headers, body и middleware traces upstream. При регенерации не добавляются timestamps, а повторные запуски должны давать одинаковые bytes. Исходные сценарии — небольшие оригинальные приложения со ссылками на соответствующие области upstream-тестов. Авторские права на Hono принадлежат его участникам, лицензия — MIT; исходники остаются во внешнем checkout.

`manifest.json` фиксирует выбранный профиль, нормализацию и явные отличия PHP. `ordered-overlap.expected` — настоящий результат upstream; поле `phpExpected` — намеренно выбранный PHP-контракт, восстанавливающий параметры вызывающего handler после `next()`. Он не выдаётся за upstream-результат. PHP-specific поведение — typed Context extensions, лимиты ресурсов и runtime adapters — имеет собственные тесты. Этот корпус fixtures не означает полный паритет с Hono.

Подмножество regex маршрутизатора допускает параметры на весь сегмент, литеральные символы, положительные или отрицательные классы символов, которые не могут совпасть с `/`, `\d`, `\w`, `\s`, экранированную пунктуацию, числовые квантификаторы и non-capturing groups/alternation. Wildcard `.`, anchors, capturing groups, lookaround, backreferences, Unicode-property escapes и любое выражение с литеральным разделителем пути отклоняются при регистрации. Используйте `[0-9]+`, `[A-Za-z_-]+` или `(?:book|film)`. Matching ограничен PCRE limits для каждого шаблона; ошибки сообщаются явно, а не превращаются в 404. Глобальный и terminal wildcard — конструкции маршрутизации, не произвольные regex.

На PHP `ConformanceTest` явно проверяет classifications и структурно сравнивает JSON, различая объекты и массивы. Нормализуются только необязательный пробел после `;` в Content-Type, имена и порядок headers. Остальные body bytes и порядок trace сохраняются.
