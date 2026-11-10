# Benchmark baseline — 10 ноября 2026

[English](README.md) | **Русский**

Это измерения php-hono на рабочей машине, а не сравнение с другими фреймворками и не обещание production throughput. Сырые данные и разброс сохранены в [results](results/).

## Среда и методика

- Apple M3 Pro, 36 GiB RAM, Darwin 25.6 arm64. Docker Desktop: 12 CPU, 8.21 GB RAM; PHP 8.5.12 Linux arm64. FPM image закреплён digest в [compose.yml](compose.yml).
- CLI: OPcache выключен, JIT disabled, memory_limit 128M. FPM: OPcache On, JIT disabled, стандартный pool. Worker: нативный FrankenPHP 1.13.5, PHP 8.5.12, один worker, без настройки JIT.
- Router/full dispatch: 10/100/1000 routes, пять измеряемых раундов по 1000 запросов после прогрева, monotonic hrtime. Каждый проход проверяет ответ.
- [dispatch.php](dispatch.php): 0/1/5/10 native либо PSR-15 passthrough middleware, позднее совпадение parameter route. Extension mode добавляет initializer одного request-state object и читает его через typed extension в endpoint.
- [scenarios.php](scenarios.php): полный dispatch static/dynamic/miss/overlap. [router.php](router.php): только matching. Готовый PSR request используется повторно; его создание не входит в warm dispatch.
- Bootstrap в dispatch.json включает прогрев autoloader в первом случае. [cold.php](cold.php) отдельно запускается десять раз в новых PHP процессах: require/autoload, регистрация 100 маршрутов, первый dispatch. Запуск процесса PHP не входит в измеряемый интервал.

## Полный dispatch

Медианы, микросекунды на запрос. Min/max каждого сценария находятся в JSON.

| Routes | Без middleware | 5 native | 5 PSR-15 | Typed state + 5 native |
| --- | ---: | ---: | ---: | ---: |
| 10 | 6.81 | 9.85 | 39.90 | 10.22 |
| 100 | 19.03 | 21.81 | 54.31 | 23.59 |
| 1000 | 151.04 | 157.35 | 186.85 | 157.20 |

| Routes | Router dynamic, µs | Full static, µs | Full miss, µs | Full overlap, µs |
| --- | ---: | ---: | ---: | ---: |
| 10 | 2.08 | 6.23 | 5.41 | 7.38 |
| 100 | 13.55 | 19.10 | 14.60 | 19.85 |
| 1000 | 143.26 | 143.71 | 111.00 | 149.30 |

Холодный bootstrap: медиана **7.41 ms**, диапазон 6.16–8.30; первый dispatch: **4.19 ms**, диапазон 3.25–13.53. Peak PHP memory в CLI прогонах — 4 MiB. Docker bind mounts и выключенный CLI OPcache влияют на cold load.

Инструментированный [profile.php](profile.php): на 1000 маршрутах matching занимает **95.5%** времени dispatch. Это измерение границ компонентов, не sampling-профиль VM. Последовательный router — основная цель следующей оптимизации на больших таблицах.

## HTTP

100 parameter routes, JSON response, RequestId + SecureHeaders, путь `/route/99/42`. Клиент Node 26.11.1 fetch на host: 20 warmup, три раунда по 1000 запросов, concurrency 1 и 8. Каждый ответ полностью прочитан и проверен.

| Runtime | Concurrency | Median req/s | Min–max req/s |
| --- | ---: | ---: | ---: |
| FPM | 1 | 579 | 558–604 |
| FPM | 8 | 2515 | 2100–2562 |
| Worker | 1 | 5135 | 4042–5213 |
| Worker | 8 | 10194 | 9970–10275 |

FPM работает внутри Docker с Caddy 2.11.7, worker — нативно на host. Различаются OS, networking, filesystem и pool; эти числа **не являются чистым сравнением режимов PHP**. Результат включает Node client, loopback и сервер. Для production выводов нужны длительные одинаковые нагрузки на одинаковом host. Сравнения со Slim/Mezzio/Flight/Phalcon этим прогоном не проводились.

## Память streaming

[stream-memory.php](stream-memory.php) прочитал **1 GiB** из generator по 64 KiB чанкам через `read(8192)`, не сохраняя весь результат. Рост выделенных PHP страниц по `memory_get_peak_usage(true)` — 0 bytes при уже выделенном heap; это не означает отсутствие использования памяти. Проверка ограничивает рост 8 MiB и включена в CI.

`getContents()` и приведение streaming body к строке материализуют содержимое и такой гарантии не имеют.

## Решение

В 1.0 сохранены OrderedRouter и ограниченный eager PSR-15 bridge. Новая реализация router не сравнивалась с baseline, поэтому доказанного ускорения для её включения нет. Следующий эксперимент — уменьшить число regex-кандидатов по method/prefix, сохранив общий порядок всех matches; одновременно сравнивать warm dispatch, cold boot, память и conformance. Bridge оптимизировать с сохранением replay/body-limit/request-view контрактов.

## Воспроизведение

В checkout с PHP 8.5 и установленными Composer dependencies:

```sh
php benchmarks/router.php 1000
php benchmarks/dispatch.php 1000
php benchmarks/scenarios.php 1000
php benchmarks/profile.php
php benchmarks/stream-memory.php
for run in 1 2 3 4 5 6 7 8 9 10; do php benchmarks/cold.php; done
docker compose -p php-hono-benchmark -f benchmarks/compose.yml up -d --wait
node benchmarks/http.mjs http://127.0.0.1:18087 1000
docker compose -p php-hono-benchmark -f benchmarks/compose.yml down
```

Worker запускается в отдельном терминале с FrankenPHP 1.13.5/PHP 8.5:

```sh
PHP_HONO_BENCH_ROOT="$PWD" XDG_CONFIG_HOME="$PWD/var/benchmark-config" XDG_DATA_HOME="$PWD/var/benchmark-data" frankenphp run --config benchmarks/Caddyfile.worker --adapter caddyfile
# В первом терминале:
node benchmarks/http.mjs http://127.0.0.1:18088 1000
```

После измерения остановите worker через Ctrl-C. Не запускайте параллельно другие benchmarks/тесты; сохраняйте JSON и версии CPU/OS/runtime, OPcache/JIT, pool/concurrency рядом с результатами. Изменение цифр само по себе не вызывает CI failure: correctness и ограниченная память проверяются отдельно.
