# Benchmark baseline — November 10, 2026

**English** | [Русский](README.ru.md)

These are php-hono measurements on a development machine, not a comparison with other frameworks or a promise of production throughput. Raw data and variation between runs are recorded in [results](results/).

## Environment and methodology

- Apple M3 Pro, 36 GiB RAM, Darwin 25.6 arm64. Docker Desktop: 12 CPUs, 8.21 GB RAM; PHP 8.5.12 Linux arm64. The FPM image is pinned by digest in [compose.yml](compose.yml).
- CLI: OPcache off, JIT disabled, memory_limit 128M. FPM: OPcache on, JIT disabled, default pool. Worker: native FrankenPHP 1.13.5, PHP 8.5.12, one worker, no JIT tuning.
- Router/full dispatch: 10/100/1000 routes, five measured rounds of 1000 requests after warmup, using monotonic hrtime. Every iteration verifies the response.
- [dispatch.php](dispatch.php): 0/1/5/10 native or PSR-15 passthrough middleware, with a parameter route matching late in the table. Extension mode adds an initializer for one request-state object and reads it through a typed extension in the endpoint.
- [scenarios.php](scenarios.php): full dispatch for static/dynamic/miss/overlap cases. [router.php](router.php): matching only. A prepared PSR request is reused; creating it is excluded from warm dispatch.
- Bootstrap in dispatch.json includes autoloader warmup in the first case. [cold.php](cold.php) runs separately ten times in new PHP processes: require/autoload, registration of 100 routes, and the first dispatch. PHP process startup is outside the measured interval.

## Full dispatch

Medians, in microseconds per request. Each scenario's min/max values are stored in JSON.

| Routes | No middleware | 5 native | 5 PSR-15 | Typed state + 5 native |
| --- | ---: | ---: | ---: | ---: |
| 10 | 6.81 | 9.85 | 39.90 | 10.22 |
| 100 | 19.03 | 21.81 | 54.31 | 23.59 |
| 1000 | 151.04 | 157.35 | 186.85 | 157.20 |

| Routes | Router dynamic, µs | Full static, µs | Full miss, µs | Full overlap, µs |
| --- | ---: | ---: | ---: | ---: |
| 10 | 2.08 | 6.23 | 5.41 | 7.38 |
| 100 | 13.55 | 19.10 | 14.60 | 19.85 |
| 1000 | 143.26 | 143.71 | 111.00 | 149.30 |

Cold bootstrap: median **7.41 ms**, range 6.16–8.30; first dispatch: **4.19 ms**, range 3.25–13.53. Peak PHP memory in CLI runs was 4 MiB. Docker bind mounts and disabled CLI OPcache affect cold loading.

Instrumented [profile.php](profile.php): with 1000 routes, matching accounts for **95.5%** of dispatch time. This measures component boundaries rather than sampling the VM. The sequential router is the main target for the next optimization on large route tables.

## HTTP

100 parameter routes, a JSON response, RequestId + SecureHeaders, path `/route/99/42`. The client uses Node 26.11.1 fetch on the host: 20 warmup requests, three rounds of 1000 requests, concurrency 1 and 8. Every response is fully read and verified.

| Runtime | Concurrency | Median req/s | Min–max req/s |
| --- | ---: | ---: | ---: |
| FPM | 1 | 579 | 558–604 |
| FPM | 8 | 2515 | 2100–2562 |
| Worker | 1 | 5135 | 4042–5213 |
| Worker | 8 | 10194 | 9970–10275 |

FPM runs inside Docker with Caddy 2.11.7; the worker runs natively on the host. The OS, networking, filesystem, and pool differ, so these figures **are not a controlled comparison of PHP execution modes**. Results include the Node client, loopback, and server. Production conclusions require sustained, identical workloads on the same host. This run did not compare Slim, Mezzio, Flight, or Phalcon.

## Streaming memory

[stream-memory.php](stream-memory.php) read **1 GiB** from a generator yielding 64 KiB chunks through `read(8192)`, without retaining the complete output. Additional PHP pages allocated according to `memory_get_peak_usage(true)` amounted to 0 bytes within the already allocated heap; this does not mean that no memory was used. The check limits growth to 8 MiB and runs in CI.

`getContents()` and casting a streaming body to a string materialize its contents and do not provide this guarantee.

## Decision

Version 1.0 retains OrderedRouter and the bounded, eager PSR-15 bridge. No alternative router implementation has been compared with the baseline, so there is no measured speedup to justify including one. The next experiment is to reduce regex candidates by method/prefix while preserving the overall order of every match. Compare warm dispatch, cold boot, memory, and conformance together. Bridge optimizations must preserve the replay, body-limit, and request-view contracts.

## Reproducing the results

In a checkout with PHP 8.5 and Composer dependencies installed:

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

Start the worker in a separate terminal using FrankenPHP 1.13.5/PHP 8.5:

```sh
PHP_HONO_BENCH_ROOT="$PWD" XDG_CONFIG_HOME="$PWD/var/benchmark-config" XDG_DATA_HOME="$PWD/var/benchmark-data" frankenphp run --config benchmarks/Caddyfile.worker --adapter caddyfile
# In the first terminal:
node benchmarks/http.mjs http://127.0.0.1:18088 1000
```

Stop the worker with Ctrl-C after measuring. Do not run other benchmarks or tests concurrently. Record the JSON results alongside CPU/OS/runtime versions, OPcache/JIT settings, and pool/concurrency settings. A change in timing figures alone does not fail CI: correctness and bounded memory are checked separately.
