<?php

declare(strict_types=1);

namespace PhpHono\Tooling;

use RuntimeException;

/**
 * The version checks deliberately validate manifest, lock and config together.
 *
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class Mago
{
    public function __construct(
        private string $root,
    ) {}

    /** @param list<string> $arguments */
    public function run(string $workspace, array $arguments): void
    {
        $version = $this->version($workspace);
        $binary =
            $this->root
            . '/vendor/carthage-software/mago/composer/bin/'
            . $version
            . '/mago-'
            . $version
            . '-'
            . Platform::target($this->root)
            . '/mago'
            . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
        if (!is_executable($binary)) {
            throw new RuntimeException(
                'Prepared Mago binary missing. Run composer install and composer tools:prepare before checking.',
            );
        }
        if (trim(Process::run($this->root, [$binary, '--version'])) !== 'mago ' . $version) {
            throw new RuntimeException('Prepared Mago binary does not match composer.lock.');
        }
        fwrite(STDOUT, Process::run($this->root, [
            $binary,
            '--workspace',
            $workspace,
            '--config',
            $workspace . '/mago.toml',
            ...$arguments,
        ]));
    }

    private function version(string $workspace): string
    {
        $manifest = Files::json($workspace . '/composer.json');
        $dev = $manifest['require-dev'] ?? null;
        $version = is_array($dev) ? $dev['carthage-software/mago'] ?? null : null;
        if (!is_string($version) || preg_match('/^\d+\.\d+\.\d+$/D', $version) !== 1) {
            throw new RuntimeException('composer.json must pin an exact Mago version.');
        }
        $lock = Files::json($workspace . '/composer.lock');
        $packages = $lock['packages-dev'] ?? null;
        $locked = null;
        if (is_array($packages)) {
            foreach ($packages as $package) {
                if (is_array($package) && ($package['name'] ?? null) === 'carthage-software/mago') {
                    $locked = $package['version'] ?? null;
                }
            }
        }
        $config = Files::read($workspace . '/mago.toml');
        if (
            $locked !== $version
            || preg_match('/^version\s*=\s*["\']([^"\']+)["\']\s*$/m', $config, $match) !== 1
            || $match[1] !== $version
        ) {
            throw new RuntimeException('Mago versions in composer.json, composer.lock and mago.toml must agree.');
        }
        return $version;
    }
}
