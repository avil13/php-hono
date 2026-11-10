<?php

declare(strict_types=1);

namespace PhpHono\Tests\Unit;

use Nyholm\Psr7\Stream;
use PhpHono\HttpException;
use PhpHono\Internal\BodyStore;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

final class BodyStoreTest extends TestCase
{
    public function testNonSeekableSourceIsReadOnceAndReplayIsIndependent(): void
    {
        $source = self::nonSeekable('hello world');
        $store = new BodyStore($source);
        self::assertFalse($source->isSeekable());
        self::assertSame('hello world', $store->bytes());
        $source->close();

        $replay = $store->replay();
        self::assertTrue($store->owns($replay));
        self::assertSame('hello', $replay->read(5));
        self::assertSame('hello world', $store->bytes());
        self::assertSame(' world', $replay->getContents());
        $replay->rewind();
        self::assertSame('hello world', (string) $replay);
    }

    public function testOversizedSourceStopsAfterLimitPlusOneAndCannotBeRetried(): void
    {
        $source = self::nonSeekable('1234567890');
        $store = new BodyStore($source, 4);

        try {
            $store->bytes();
            self::fail('Oversized body was accepted');
        } catch (HttpException $exception) {
            self::assertSame(413, $exception->status);
        }

        self::assertSame('67890', $source->getContents());
        $this->expectException(HttpException::class);
        $store->bytes(100);
    }

    public function testLowerLimitAlsoChecksCachedBytes(): void
    {
        $store = new BodyStore(Stream::create('12345'));
        self::assertSame('12345', $store->bytes());
        $this->expectException(HttpException::class);
        $store->bytes(4);
    }

    public function testEmptyBodyIsAllowedAtZeroLimit(): void
    {
        $store = new BodyStore(Stream::create(''), 0);
        self::assertSame('', $store->bytes());
        self::assertSame('', (string) $store->replay());
    }

    public function testReplayCannotChangeCachedBytes(): void
    {
        $store = new BodyStore(Stream::create('original'));
        $replay = $store->replay();
        self::assertFalse($replay->isWritable());

        try {
            $replay->write('changed');
            self::fail('Replay stream was writable');
        } catch (RuntimeException) {
            self::assertSame('original', $store->bytes());
        }

        self::assertNull($replay->detach());
        self::assertNull($replay->getSize());
        self::assertFalse($replay->isReadable());
        self::assertSame('', (string) $replay);
        self::assertSame('original', $store->bytes());
    }

    public function testReplaySupportsRelativeSeekingAndEndOfStream(): void
    {
        $replay = new BodyStore(Stream::create('abcdef'))->replay();
        $replay->seek(-2, SEEK_END);
        self::assertSame(4, $replay->tell());
        self::assertSame('e', $replay->read(1));
        self::assertFalse($replay->eof());
        $replay->seek(-3, SEEK_CUR);
        self::assertSame('cdef', $replay->getContents());
        self::assertTrue($replay->eof());
        $this->expectException(RuntimeException::class);
        $replay->seek(-1);
    }

    public static function nonSeekable(string $contents): StreamInterface
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($pair === false) {
            throw new RuntimeException('Cannot create an in-memory test stream');
        }

        fwrite($pair[1], $contents);
        fclose($pair[1]);

        return Stream::create($pair[0]);
    }
}
