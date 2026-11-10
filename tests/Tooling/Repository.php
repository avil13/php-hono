<?php

declare(strict_types=1);

namespace PhpHono\Tests\Tooling;

use PhpHono\Tooling\Files;
use RuntimeException;

final class Repository
{
    public readonly string $path;

    public function __construct()
    {
        $this->path = sys_get_temp_dir() . '/php-hono-hook-test-' . bin2hex(random_bytes(8));
        mkdir($this->path, permissions: 0o700);
        mkdir($this->path . '/tmp', permissions: 0o700);
        $this->run(['git', 'init', '--quiet']);
        $this->write('.gitignore', "vendor/\ntmp/\n");
        $this->write('composer.json', '{"require-dev":{"carthage-software/mago":"1.59.0"}}');
        $this->write('composer.lock', '{"packages-dev":[{"name":"carthage-software/mago","version":"1.59.0"}]}');
        $this->write('mago.toml', "version = \"1.59.0\"\nphp-version = \"8.5\"\n");
        $this->write('src/example.php', "<?php\ndeclare(strict_types=1);\n");
        require_once dirname(__DIR__, levels: 2) . '/tools/quality/Files.php';
        $root = dirname(__DIR__, levels: 2);
        $this->write('.githooks/pre-commit', Files::read($root . '/.githooks/pre-commit'));
        chmod($this->path . '/.githooks/pre-commit', permissions: 0o755);
        $this->write('tools/quality.php', Files::read($root . '/tools/quality.php'));
        foreach (Files::glob($root . '/tools/quality/*.php') as $file) {
            $this->write('tools/quality/' . basename($file), Files::read($file));
        }
        $this->write(self::binaryPath(), self::fakeMago());
        chmod($this->path . '/' . self::binaryPath(), permissions: 0o755);
        $this->run(['git', 'add', '.']);
    }

    public function write(string $path, string $content): void
    {
        $directory = dirname($this->path . '/' . $path);
        if (!is_dir($directory)) {
            mkdir($directory, permissions: 0o700, recursive: true);
        }
        if (file_put_contents($this->path . '/' . $path, $content) !== strlen($content)) {
            throw new RuntimeException('Cannot prepare test file.');
        }
    }

    /** @param list<string> $command
     * @return array{code: int, output: string}
     */
    public function run(array $command): array
    {
        $environment = getenv();
        $environment['TMPDIR'] = $this->path . '/tmp';
        $environment['MAGO_NO_VERSION_CHECK'] = '1';
        $environment['MAGO_PHP_VERSION'] = '7.4';
        $outputHandle = tmpfile();
        if ($outputHandle === false) {
            throw new RuntimeException('Cannot open test command output.');
        }
        $process = proc_open(
            $command,
            [
                0 => ['file', '/dev/null', 'r'],
                1 => $outputHandle,
                2 => $outputHandle,
            ],
            $pipes,
            $this->path,
            $environment,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot execute test command.');
        }
        $code = proc_close($process);
        rewind($outputHandle);
        $output = stream_get_contents($outputHandle);
        fclose($outputHandle);
        if ($output === false) {
            throw new RuntimeException('Cannot read test command output.');
        }
        return ['code' => $code, 'output' => $output];
    }

    /** @return array{code: int, output: string} */
    public function check(): array
    {
        return $this->run([PHP_BINARY, dirname(__DIR__, levels: 2) . '/tools/quality.php', 'staged']);
    }

    public function remove(): void
    {
        require_once dirname(__DIR__, levels: 2) . '/tools/quality/Files.php';
        Files::removeDirectory($this->path);
    }

    public static function binaryPath(): string
    {
        require_once dirname(__DIR__, levels: 2) . '/tools/quality/Files.php';
        require_once dirname(__DIR__, levels: 2) . '/tools/quality/Process.php';
        require_once dirname(__DIR__, levels: 2) . '/tools/quality/Platform.php';
        return (
            'vendor/carthage-software/mago/composer/bin/1.59.0/mago-1.59.0-'
            . \PhpHono\Tooling\Platform::target(dirname(__DIR__, levels: 2))
            . '/mago'
        );
    }

    private static function fakeMago(): string
    {
        // A deterministic executable isolates snapshot correctness from Mago's own rules.
        return "#!/usr/bin/env php\n" . <<<'PHP'
            <?php
            declare(strict_types=1);
            if (($argv[1] ?? '') === '--version') {
                echo "mago 1.59.0\n";
                exit(0);
            }
            if (getenv('MAGO_NO_VERSION_CHECK') !== false || getenv('MAGO_PHP_VERSION') !== false) {
                exit(88);
            }
            if (($argv[1] ?? '') !== '--workspace' || ($argv[3] ?? '') !== '--config') {
                exit(89);
            }
            $workspace = $argv[2];
            if ($argv[4] !== $workspace . '/mago.toml' || !is_file($argv[4])) {
                exit(90);
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($workspace . '/src')) as $file) {
                if ($file->isFile() && str_contains(file_get_contents($file->getPathname()), 'BAD')) {
                    fwrite(STDERR, "Deliberate format/lint violation in staged code.\n");
                    exit(1);
                }
            }
            PHP;
    }
}
