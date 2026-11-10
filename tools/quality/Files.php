<?php

declare(strict_types=1);

namespace PhpHono\Tooling;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class Files
{
    public static function temporary(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            throw new RuntimeException('Cannot create process file.');
        }
        return $path;
    }

    /** @return list<string> */
    public static function glob(string $pattern): array
    {
        $paths = glob($pattern);
        return $paths === false ? [] : $paths;
    }

    /** @return array<string, mixed> */
    public static function json(string $path): array
    {
        $data = json_decode(self::read($path), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Expected JSON object: ' . $path);
        }
        /** @var array<string, mixed> $data */
        return $data;
    }

    public static function read(string $path): string
    {
        if (!is_file($path)) {
            throw new RuntimeException('Required file missing: ' . $path);
        }
        $data = file_get_contents($path);
        if ($data === false) {
            throw new RuntimeException('Cannot read ' . $path);
        }
        return $data;
    }

    public static function write(string $path, string $bytes): void
    {
        if (file_put_contents($path, $bytes) !== strlen($bytes)) {
            throw new RuntimeException('Cannot write ' . $path);
        }
    }

    public static function removeDirectory(string $directory): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($directory);
    }
}
