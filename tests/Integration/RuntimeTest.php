<?php

declare(strict_types=1);

namespace PhpHono\Tests\Integration;

use LogicException;
use PhpHono\Adapter\FrankenPhpWorker;
use PhpHono\Adapter\Sapi;
use PhpHono\Hono;
use PhpHono\HttpException;
use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * SAPI input boundaries and HTTP emission share the isolated server fixture.
 * @mago-expect lint:too-many-methods
 */
#[BackupGlobals(true)]
final class RuntimeTest extends TestCase
{
    public function testSapiPreservesRawTargetAndDoesNotTrustForwardedSchemeOrAddress(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/a%2Fb?x=one+two&x=three',
            'SERVER_NAME' => 'example.test',
            'SERVER_PORT' => '80',
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_FOR' => '192.0.2.1',
        ];
        $_GET = ['x' => 'three'];
        $request = Sapi::request();
        self::assertSame('/a%2Fb?x=one+two&x=three', $request->getRequestTarget());
        self::assertSame('http', $request->getUri()->getScheme());
        self::assertSame('127.0.0.1', $request->getServerParams()['REMOTE_ADDR']);
    }

    public function testMalformedRawPathFailsBeforePsrNormalization(): void
    {
        $_SERVER['REQUEST_URI'] = '/%GG';
        $this->expectException(HttpException::class);
        Sapi::request();
    }

    public function testPhpMultipartFieldsAndNoFileAreMarkedAsParsed(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/upload',
            'CONTENT_TYPE' => 'multipart/form-data; boundary=example',
        ];
        $_POST = ['field' => 'value'];
        $_FILES = ['file' => [
            'name' => '',
            'type' => '',
            'tmp_name' => '',
            'error' => UPLOAD_ERR_NO_FILE,
            'size' => 0,
        ]];
        $request = Sapi::request();
        self::assertTrue($request->getAttribute('php_hono.multipart_parsed'));
        self::assertSame(['field' => 'value'], $request->getParsedBody());
        $file = $request->getUploadedFiles()['file'];
        self::assertInstanceOf(\Psr\Http\Message\UploadedFileInterface::class, $file);
        self::assertSame(UPLOAD_ERR_NO_FILE, $file->getError());
    }

    public function testUploadSizeErrorsAre413(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/upload'];
        $_FILES = ['file' => [
            'name' => '',
            'type' => '',
            'tmp_name' => '',
            'error' => UPLOAD_ERR_INI_SIZE,
            'size' => 0,
        ]];
        try {
            Sapi::request();
            self::fail('Failed uploads must not silently look like empty input.');
        } catch (HttpException $error) {
            self::assertSame(413, $error->status);
        }
    }

    public function testPostLimitRejectsPhpDiscardedInput(): void
    {
        $limit = ini_parse_quantity((string) ini_get('post_max_size'));
        self::assertGreaterThan(0, $limit, 'The runtime test configuration needs a positive post_max_size.');
        $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/', 'CONTENT_LENGTH' => (string) ($limit + 1)];
        try {
            Sapi::request();
            self::fail('PHP post_max_size overflow must return 413.');
        } catch (HttpException $error) {
            self::assertSame(413, $error->status);
        }
    }

    public function testWorkerFailsClearlyOutsideFrankenPhp(): void
    {
        if (function_exists('frankenphp_handle_request')) {
            self::markTestSkipped('This guard is specific to non-FrankenPHP runtimes.');
        }
        $this->expectException(RuntimeException::class);
        FrankenPhpWorker::run(new Hono());
    }

    public function testHttpFirstChunkIsDeliveredBeforeProducerContinues(): void
    {
        $server = $this->server();
        $socket = $server->connect('/stream');
        try {
            $received = '';
            while (!str_contains($received, 'first')) {
                $chunk = fread($socket, length: 1);
                self::assertNotFalse($chunk);
                self::assertNotSame('', $chunk, 'Response must flush before the producer resumes.');
                $received .= $chunk;
            }
            self::assertFileDoesNotExist($server->directory . '/closed');
            file_put_contents($server->directory . '/continue', data: 'yes');
            self::assertSame('second', stream_get_contents($socket));
            self::assertFileExists($server->directory . '/closed');
        } finally {
            fclose($socket);
            $server->close();
        }
    }

    public function testHttpHeadAnd204SuppressProducerAndCookiesRemainSeparate(): void
    {
        $server = $this->server();
        try {
            self::assertStringEndsWith("\r\n\r\n", $server->request('/head', 'HEAD'));
            $noContent = $server->request('/no-body');
            self::assertStringEndsWith("\r\n\r\n", $noContent);
            self::assertStringNotContainsString('Content-Length:', $noContent);
            $reset = $server->request('/reset-content');
            self::assertStringContainsString('Content-Length: 0', $reset);
            self::assertStringEndsWith("\r\n\r\n", $reset);
            self::assertFileDoesNotExist($server->directory . '/started');
            $response = $server->request('/cookies');
            self::assertStringContainsString("Set-Cookie: a=1; Path=/\r\nSet-Cookie: b=2; Path=/", $response);
            self::assertStringEndsWith('ok', $response);
        } finally {
            $server->close();
        }
    }

    public function testHttpProducerFailureClosesBodyWithoutASecondResponse(): void
    {
        $server = $this->server();
        try {
            $response = $server->request('/failure');
            self::assertStringStartsWith('HTTP/1.1 200', $response);
            self::assertStringEndsWith('first', $response);
            self::assertStringNotContainsString('Secret', $response);
            self::assertFileExists($server->directory . '/failed-closed');
            self::assertSame(RuntimeException::class, file_get_contents($server->directory . '/error'));
        } finally {
            $server->close();
        }
    }

    public function testHeadersAlreadySentAreReportedWithoutEmittingAnotherResponse(): void
    {
        $server = $this->server();
        try {
            $response = $server->request('/already-sent');
            self::assertStringEndsWith('prefix', $response);
            self::assertStringNotContainsString('must not be emitted', $response);
            self::assertSame(LogicException::class, file_get_contents($server->directory . '/error'));
        } finally {
            $server->close();
        }
    }

    private function server(): RuntimeServer
    {
        if (str_contains(basename(PHP_BINARY), 'frankenphp')) {
            self::markTestSkipped(
                'FrankenPHP php-cli does not support PHP CLI server flags; run with regular PHP CLI.',
            );
        }
        return new RuntimeServer();
    }
}
