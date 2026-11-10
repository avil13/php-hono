<?php

declare(strict_types=1);

namespace PhpHono\Tests\Tooling;

use PHPUnit\Framework\TestCase;

final class PreCommitTest extends TestCase
{
    private Repository $repository;

    protected function setUp(): void
    {
        $this->repository = new Repository();
    }

    protected function tearDown(): void
    {
        $this->repository->remove();
    }

    public function testPartialStagingChecksIndexAndPreservesBothCopies(): void
    {
        $repo = $this->repository;
        $repo->write('src/example.php', '<?php // BAD');
        $repo->run(['git', 'add', 'src/example.php']);
        $repo->write('src/example.php', '<?php // repaired, not staged');
        $before = file_get_contents($repo->path . '/.git/index');
        self::assertNotSame(0, $repo->check()['code']);
        self::assertSame($before, file_get_contents($repo->path . '/.git/index'));
        self::assertSame('<?php // repaired, not staged', file_get_contents($repo->path . '/src/example.php'));
        self::assertSame([], glob($repo->path . '/tmp/*'));

        $repo->run(['git', 'add', 'src/example.php']);
        $repo->write('src/example.php', '<?php // BAD, not staged');
        $before = file_get_contents($repo->path . '/.git/index');
        $result = $repo->check();
        self::assertSame(0, $result['code'], $result['output']);
        self::assertSame($before, file_get_contents($repo->path . '/.git/index'));
        self::assertSame('<?php // BAD, not staged', file_get_contents($repo->path . '/src/example.php'));
        self::assertSame([], glob($repo->path . '/tmp/*'));
    }

    public function testStagedConfigRenameDeletionAndPathsWithSpaces(): void
    {
        $repo = $this->repository;
        $repo->run(['git', 'mv', 'src/example.php', 'src/with spaces.php']);
        $repo->write('src/deleted.php', '<?php // BAD');
        $repo->run(['git', 'add', 'src/deleted.php']);
        $repo->run(['git', 'rm', '--force', 'src/deleted.php']);
        $repo->write('src/deleted.php', '<?php // BAD, untracked');
        self::assertSame(0, $repo->check()['code']);

        $repo->write('mago.toml', "version = \"0.0.0\"\n");
        $repo->run(['git', 'add', 'mago.toml']);
        $repo->write('mago.toml', "version = \"1.59.0\"\n");
        self::assertNotSame(0, $repo->check()['code']);
        self::assertSame([], glob($repo->path . '/tmp/*'));
    }

    public function testMissingBinaryFailsWithoutDownloading(): void
    {
        $repo = $this->repository;
        unlink($repo->path . '/' . Repository::binaryPath());
        $result = $repo->check();
        self::assertNotSame(0, $result['code']);
        self::assertStringContainsString('Prepared Mago binary missing', $result['output']);
        self::assertFileDoesNotExist($repo->path . '/' . Repository::binaryPath());
        self::assertSame([], glob($repo->path . '/tmp/*'));
    }

    public function testOnlyTheCurrentPlatformBinaryIsExecuted(): void
    {
        $repo = $this->repository;
        $wrong = 'vendor/carthage-software/mago/composer/bin/1.59.0/mago-1.59.0-000-other-platform/mago';
        $repo->write($wrong, "#!/bin/sh\nexit 89\n");
        chmod($repo->path . '/' . $wrong, permissions: 0o755);
        self::assertSame(0, $repo->check()['code']);
        unlink($repo->path . '/' . Repository::binaryPath());
        $result = $repo->check();
        self::assertNotSame(0, $result['code']);
        self::assertStringContainsString('Prepared Mago binary missing', $result['output']);
    }

    public function testActualGitCommitRunsHookAgainstIndexWithoutComposer(): void
    {
        $repo = $this->repository;
        $commit = [
            'git',
            '-c',
            'core.hooksPath=.githooks',
            '-c',
            'user.name=Hook Test',
            '-c',
            'user.email=hook@example.test',
            'commit',
            '--quiet',
            '-m',
            'Verify staged hook',
        ];
        $repo->write('src/example.php', '<?php // BAD staged');
        $repo->run(['git', 'add', 'src/example.php']);
        $repo->write('src/example.php', '<?php // repaired working tree');
        self::assertNotSame(0, $repo->run($commit)['code']);
        $repo->run(['git', 'add', 'src/example.php']);
        $repo->write('src/example.php', '<?php // BAD unstaged');
        $result = $repo->run($commit);
        self::assertSame(0, $result['code'], $result['output']);
        self::assertSame(
            '<?php // repaired working tree',
            $repo->run(['git', 'show', 'HEAD:src/example.php'])['output'],
        );
        self::assertSame('<?php // BAD unstaged', file_get_contents($repo->path . '/src/example.php'));
    }

    public function testInstallerPreservesExistingHooks(): void
    {
        $repo = $this->repository;
        $command = [PHP_BINARY, dirname(__DIR__, levels: 2) . '/tools/install-hooks.php'];
        $repo->run(['git', 'config', '--local', 'core.hooksPath', 'custom-hooks']);
        self::assertNotSame(0, $repo->run($command)['code']);
        self::assertSame("custom-hooks\n", $repo->run(['git', 'config', '--get', 'core.hooksPath'])['output']);
        $repo->run(['git', 'config', '--local', '--unset', 'core.hooksPath']);
        $repo->write('.git/hooks/pre-commit', 'existing hook');
        self::assertNotSame(0, $repo->run($command)['code']);
        self::assertSame('existing hook', file_get_contents($repo->path . '/.git/hooks/pre-commit'));
        unlink($repo->path . '/.git/hooks/pre-commit');
        self::assertSame(0, $repo->run($command)['code']);
        self::assertSame(".githooks\n", $repo->run(['git', 'config', '--get', 'core.hooksPath'])['output']);
        self::assertTrue(is_executable($repo->path . '/.githooks/pre-commit'));
    }
}
