<?php

declare(strict_types=1);

use PhpHono\Tooling\HookInstaller;

require __DIR__ . '/quality/Files.php';
require __DIR__ . '/quality/Process.php';
require __DIR__ . '/quality/IndexSnapshot.php';
require __DIR__ . '/quality/Mago.php';
require __DIR__ . '/quality/Checks.php';
require __DIR__ . '/quality/HookInstaller.php';

try {
    $root = getcwd();
    if ($root === false) {
        throw new RuntimeException('Cannot determine workspace.');
    }
    new HookInstaller($root)->install();
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
