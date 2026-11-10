<?php

declare(strict_types=1);

namespace PhpHono\Tooling;

use RuntimeException;

final readonly class Checks
{
    public function __construct(
        private string $root,
    ) {}

    public function check(string $mode): void
    {
        $arguments = match ($mode) {
            'format' => ['fmt'],
            'format:check' => ['fmt', '--check'],
            'lint' => ['lint'],
            'staged' => null,
            default => throw new RuntimeException('Expected format, format:check, lint or staged.'),
        };
        if ($arguments !== null) {
            new Mago($this->root)->run($this->root, $arguments);
            return;
        }

        $snapshot = sys_get_temp_dir() . '/php-hono-staged-' . bin2hex(random_bytes(12));
        if (!mkdir($snapshot, permissions: 0o700)) {
            throw new RuntimeException('Cannot create staged snapshot.');
        }
        try {
            new IndexSnapshot($this->root)->export($snapshot);
            if (is_dir($this->root . '/vendor') && !file_exists($snapshot . '/vendor')) {
                if (!symlink($this->root . '/vendor', $snapshot . '/vendor')) {
                    throw new RuntimeException('Cannot expose dependency definitions to staged check.');
                }
            }
            new Mago($this->root)->run($snapshot, ['fmt', '--check']);
            new Mago($this->root)->run($snapshot, ['lint']);
        } finally {
            Files::removeDirectory($snapshot);
        }
    }
}
