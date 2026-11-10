#!/bin/sh
set -eu
cd "$(dirname "$0")/../.."
mkdir -p var/runtime-fpm
chmod 777 var/runtime-fpm
cleanup() {
    docker compose -p php-hono-runtime -f tools/runtime/compose.yml logs > var/runtime-fpm.log 2>&1
    docker compose -p php-hono-runtime -f tools/runtime/compose.yml down --remove-orphans
}
trap cleanup EXIT HUP INT TERM
docker compose -p php-hono-runtime -f tools/runtime/compose.yml up -d --force-recreate
python3 tools/runtime/check.py --url "http://127.0.0.1:${PHP_HONO_FPM_PORT:-18085}" --state-dir var/runtime-fpm > var/runtime-fpm-result.json
cat var/runtime-fpm-result.json
