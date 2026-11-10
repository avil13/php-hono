<?php

declare(strict_types=1);

namespace PhpHono\Tests\Unit;

use PhpHono\Hono;
use PhpHono\Middleware\StaticFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

final class StaticFilesTest extends TestCase
{
    private string $root;
    private string $outside;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/php-hono-static-' . bin2hex(random_bytes(8));
        $this->outside = $this->root . '-private.txt';
        mkdir($this->root);
        mkdir($this->root . '/folder');
        file_put_contents($this->root . '/hello.txt', data: 'public content');
        file_put_contents($this->outside, data: 'PRIVATE CONTENT');
        symlink($this->outside, $this->root . '/escape.txt');
        symlink($this->root . '/hello.txt', $this->root . '/inside.txt');
    }

    protected function tearDown(): void
    {
        foreach (['hello.txt', 'escape.txt', 'inside.txt'] as $file) {
            unlink($this->root . '/' . $file);
        }
        rmdir($this->root . '/folder');
        rmdir($this->root);
        unlink($this->outside);
    }

    public function testGetAndHeadServeOnlyTheConfiguredFile(): void
    {
        $app = new Hono();
        $app->use('*', new StaticFiles($this->root, '/static'));
        $response = $app->request('/static/hello.txt');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('public content', (string) $response->getBody());
        self::assertSame('14', $response->getHeaderLine('Content-Length'));
        self::assertSame('text/plain', $response->getHeaderLine('Content-Type'));
        $head = $app->request('/static/hello.txt', ['method' => 'HEAD']);
        self::assertSame(200, $head->getStatusCode());
        self::assertSame('', (string) $head->getBody());
        self::assertSame('14', $head->getHeaderLine('Content-Length'));
        self::assertSame($response->getHeaderLine('Last-Modified'), $head->getHeaderLine('Last-Modified'));
        self::assertSame('public content', (string) $app->request('/static/inside.txt')->getBody());
        $response->getBody()->close();
        self::assertFalse($response->getBody()->isReadable());
    }

    public function testStreamFactoryFailureClosesTheOpenedFile(): void
    {
        $opened = null;
        $streams = $this->createMock(StreamFactoryInterface::class);
        $streams
            ->expects(self::once())
            ->method('createStreamFromResource')
            ->willReturnCallback(static function ($resource) use (&$opened): StreamInterface {
                $opened = $resource;
                throw new RuntimeException('Stream factory failed');
            });
        $app = new Hono();
        $app->use('*', new StaticFiles($this->root, streams: $streams));
        $response = $app->request('/hello.txt');
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('resource (closed)', gettype($opened));
        self::assertSame('Internal Server Error', (string) $response->getBody());
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function deniedPaths(): iterable
    {
        yield 'outside symlink' => ['/static/escape.txt', 'GET', 403];
        yield 'encoded traversal' => ['/static/%2e%2e/private.txt', 'GET', 403];
        yield 'encoded slash' => ['/static/folder%2fhello.txt', 'GET', 403];
        yield 'encoded backslash' => ['/static/folder%5chello.txt', 'GET', 403];
        yield 'NUL rejected by core' => ['/static/hello.txt%00', 'GET', 400];
        yield 'directory listing' => ['/static/folder/', 'GET', 404];
        yield 'missing file' => ['/static/missing.txt', 'GET', 404];
        yield 'prefix boundary' => ['/static2/hello.txt', 'GET', 404];
        yield 'POST is not served' => ['/static/hello.txt', 'POST', 404];
    }

    #[DataProvider('deniedPaths')]
    public function testTraversalAndUnsupportedRequestsNeverExposeFiles(
        string $path,
        string $method,
        int $expectedStatus,
    ): void {
        $app = new Hono();
        $app->use('*', new StaticFiles($this->root, '/static'));
        $response = $app->request($path, ['method' => $method]);
        self::assertSame($expectedStatus, $response->getStatusCode());
        self::assertStringNotContainsString('PRIVATE CONTENT', (string) $response->getBody());
    }
}
