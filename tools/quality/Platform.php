<?php

declare(strict_types=1);

namespace PhpHono\Tooling;

use RuntimeException;

/** Platform names follow the pinned Mago Composer wrapper's release layout. */
final class Platform
{
    public static function target(string $root): string
    {
        $architecture = match (strtolower(php_uname('m'))) {
            'x86_64', 'amd64' => 'x86_64',
            'arm64', 'aarch64' => 'aarch64',
            default => throw new RuntimeException(
                'Quality tools require x86_64 or aarch64. Run them in the PHP 8.5 CI container.',
            ),
        };
        return match (PHP_OS_FAMILY) {
            'Darwin' => $architecture . '-apple-darwin',
            'Linux' => $architecture . '-unknown-linux-' . self::libc($root),
            'Windows' => $architecture === 'x86_64'
                ? 'x86_64-pc-windows-msvc'
                : throw new RuntimeException('Mago has no Windows ARM release.'),
            'BSD' => $architecture === 'x86_64'
                ? 'x86_64-unknown-freebsd'
                : throw new RuntimeException('Mago has no FreeBSD ARM release.'),
            default => throw new RuntimeException('Unsupported Mago platform.'),
        };
    }

    private static function libc(string $root): string
    {
        $description = Process::run($root, ['ldd', '--version'], accepted: [0, 1, 127]);
        return str_contains($description, 'musl') || str_contains($description, 'Exec failed') ? 'musl' : 'gnu';
    }
}
