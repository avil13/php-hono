#!/bin/sh
set -eu
cd "$(dirname "$0")/../.."
: "${FRANKENPHP_BINARY:?Set FRANKENPHP_BINARY to the FrankenPHP 1.13.5 executable.}"
case "$("$FRANKENPHP_BINARY" version)" in
    *"v1.13.5"*"PHP 8.5."*) ;;
    *) echo 'Runtime tests require FrankenPHP 1.13.5 with PHP 8.5.' >&2; exit 1 ;;
esac
mkdir -p var/runtime-worker
export PHP_HONO_TEST_ROOT="$PWD"
export PHP_HONO_TEST_DIRECTORY="$PWD/var/runtime-worker"
export XDG_CONFIG_HOME="$PWD/var/runtime-worker/config"
export XDG_DATA_HOME="$PWD/var/runtime-worker/data"
result_file="${PHP_HONO_TEST_RESULT:-var/runtime-worker-result.json}"
"$FRANKENPHP_BINARY" run --config tools/runtime/Caddyfile.worker > var/runtime-worker.log 2>&1 &
worker_pid=$!
cleanup() {
    kill "$worker_pid" 2>/dev/null || true
    wait "$worker_pid" || true
}
trap cleanup EXIT HUP INT TERM
python3 tools/runtime/check.py --url "http://127.0.0.1:${PHP_HONO_WORKER_PORT:-18086}" --state-dir var/runtime-worker --worker > "$result_file"
kill -0 "$worker_pid"
cat "$result_file"
