<?php

declare(strict_types=1);

use PhpHono\Tooling\Files;
use PhpHono\Tooling\Process;

require dirname(__DIR__) . '/quality/Files.php';
require dirname(__DIR__) . '/quality/Process.php';

$root = dirname(__DIR__, levels: 2);
$composerPath = getenv('COMPOSER_BINARY');
$composer = is_string($composerPath) && $composerPath !== '' ? [PHP_BINARY, $composerPath] : ['composer'];
$version = '1.0.0';
$directory = sys_get_temp_dir() . '/php-hono-package-' . bin2hex(random_bytes(8));
mkdir($directory, permissions: 0o700);
try {
    if (!is_dir($root . '/build')) {
        mkdir($root . '/build', permissions: 0o755);
    }
    Process::run($root, [...$composer, 'validate', '--strict']);
    Process::run($root, [...$composer, 'archive', '--format=zip', '--dir=build', '--file=php-hono-' . $version]);
    $archive = $root . '/build/php-hono-' . $version . '.zip';
    $files = array_filter(explode("\n", trim(Process::run($root, ['unzip', '-Z1', $archive]))));
    $forbidden = '~^(?:vendor|var|build|coverage|tests|spec|tools|benchmarks|\.git|\.github|\.githooks)(?:/|$)|^(?:mago\.toml|phpstan\.neon\.dist|phpunit\.xml|composer\.lock|AGENTS\.md|\.DS_Store)$~D';
    foreach ($files as $file) {
        if (preg_match($forbidden, $file) === 1) {
            throw new RuntimeException('Development or generated file leaked into package: ' . $file);
        }
    }
    foreach (['composer.json', 'src/Hono.php', 'LICENSE', 'README.md'] as $required) {
        if (!in_array($required, $files, strict: true)) {
            throw new RuntimeException('Required package file missing: ' . $required);
        }
    }
    $manifest = Files::json($root . '/composer.json');
    $package = array_intersect_key($manifest, array_flip(['name', 'type', 'license', 'require', 'autoload']));
    // This version exists only in the disposable consumer's local package repository.
    $package['version'] = $version;
    $sha256 = hash_file('sha256', $archive);
    $package['dist'] = [
        'type' => 'zip',
        'url' => 'file://' . $archive,
        'reference' => $sha256,
        'shasum' => sha1_file($archive),
    ];
    Files::write($directory . '/composer.json', json_encode([
        'name' => 'php-hono/package-smoke',
        'type' => 'project',
        'require' => ['php-hono/php-hono' => $version],
        'repositories' => [['type' => 'package', 'package' => $package]],
        'config' => ['allow-plugins' => new stdClass()],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
        . "\n");
    Process::run($directory, [
        ...$composer,
        'install',
        '--no-dev',
        '--prefer-dist',
        '--no-interaction',
        '--no-progress',
    ]);
    $smoke = Files::read(__DIR__ . '/consumer.php');
    Files::write($directory . '/smoke.php', $smoke);
    $result = Process::run($directory, [PHP_BINARY, 'smoke.php']);
    $summary = [
        'archive' => $archive,
        'bytes' => filesize($archive),
        'sha256' => $sha256,
        'entries' => count($files),
        'consumer' => json_decode($result, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR),
        'published' => false,
    ];
    Files::write(
        $root . '/build/package-check.json',
        json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n",
    );
    fwrite(STDOUT, json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
} finally {
    Files::removeDirectory($directory);
}
