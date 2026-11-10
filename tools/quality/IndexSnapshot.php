<?php

declare(strict_types=1);

namespace PhpHono\Tooling;

use RuntimeException;

final readonly class IndexSnapshot
{
    public function __construct(
        private string $root,
    ) {}

    public function export(string $snapshot): void
    {
        $listing = Process::run($this->root, ['git', 'ls-files', '--stage', '-z']);
        /** @var list<array{string, string, string}> $entries */
        $entries = [];
        foreach (explode("\0", $listing) as $record) {
            if ($record === '') {
                continue;
            }
            if (preg_match('/^(100644|100755) ([a-f0-9]+) 0\t([\s\S]+)$/D', $record, $entry) !== 1) {
                throw new RuntimeException(
                    'Staged snapshot requires resolved regular files; symlinks and submodules are unsupported.',
                );
            }
            $path = $entry[3];
            if (str_starts_with($path, '/') || array_intersect(explode('/', $path), ['', '.', '..']) !== []) {
                throw new RuntimeException('Invalid staged path.');
            }
            $entries[] = [$entry[1], $entry[2], $path];
        }
        $objects = Process::run(
            $this->root,
            ['git', 'cat-file', '--batch'],
            implode(separator: "\n", array: array_column($entries, 1)) . "\n",
        );
        $offset = 0;
        foreach ($entries as [$mode, $hash, $path]) {
            $end = strpos($objects, needle: "\n", offset: $offset);
            if ($end === false) {
                throw new RuntimeException('Incomplete staged object stream.');
            }
            $header = substr($objects, $offset, $end - $offset);
            if (preg_match('/^' . $hash . ' blob (\d+)$/D', $header, $match) !== 1) {
                throw new RuntimeException('Unexpected staged object.');
            }
            $size = (int) $match[1];
            $offset = $end + 1;
            $bytes = substr($objects, $offset, $size);
            if (strlen($bytes) !== $size) {
                throw new RuntimeException('Incomplete staged file.');
            }
            $offset += $size + 1;
            $destination = $snapshot . '/' . $path;
            $directory = dirname($destination);
            if (!is_dir($directory) && !mkdir($directory, permissions: 0o700, recursive: true)) {
                throw new RuntimeException('Cannot create snapshot directory.');
            }
            Files::write($destination, $bytes);
            if ($mode === '100755' && !chmod($destination, permissions: 0o755)) {
                throw new RuntimeException('Cannot preserve executable staged file.');
            }
        }
    }
}
