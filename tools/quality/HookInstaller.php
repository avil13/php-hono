<?php

declare(strict_types=1);

namespace PhpHono\Tooling;

use RuntimeException;

final readonly class HookInstaller
{
    public function __construct(
        private string $root,
    ) {}

    public function install(): void
    {
        $configured = trim(Process::run($this->root, ['git', 'config', '--get', 'core.hooksPath'], '', [0, 1]));
        if ($configured !== '' && $configured !== '.githooks') {
            throw new RuntimeException(
                'Existing core.hooksPath preserved. Add composer check:staged to that pre-commit hook.',
            );
        }
        $hook = $this->root . '/.githooks/pre-commit';
        if (!is_file($hook)) {
            throw new RuntimeException('Missing versioned .githooks/pre-commit.');
        }
        $defaultHooks = trim(Process::run($this->root, ['git', 'rev-parse', '--git-path', 'hooks']));
        if (!str_starts_with($defaultHooks, '/')) {
            $defaultHooks = $this->root . '/' . $defaultHooks;
        }
        foreach (Files::glob($defaultHooks . '/*') as $existing) {
            if (is_file($existing) && !str_ends_with($existing, '.sample')) {
                throw new RuntimeException(
                    'Existing Git hooks preserved. Add composer check:staged to the current pre-commit hook.',
                );
            }
        }
        if (!chmod($hook, permissions: 0o755)) {
            throw new RuntimeException('Cannot mark the pre-commit hook executable.');
        }
        Process::run($this->root, ['git', 'config', '--local', 'core.hooksPath', '.githooks']);
        fwrite(STDOUT, data: "Installed .githooks/pre-commit locally.\n");
    }
}
