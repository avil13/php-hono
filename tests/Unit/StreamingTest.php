<?php

declare(strict_types=1);

namespace PhpHono\Tests\Unit;

use Generator;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PhpHono\Context;
use PhpHono\Helper\Streaming;
use PhpHono\Hono;
use PhpHono\HonoRequest;
use PhpHono\Http\StreamingBody;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Producer lifecycle and SSE framing have separate regression cases for the same public API.
 * @mago-expect lint:too-many-methods
 */
final class StreamingTest extends TestCase
{
    public function testMetadataAndClosingUnstartedStreamNeverInvokeProducer(): void
    {
        $started = false;
        $body = new StreamingBody(static function () use (&$started): Generator {
            $started = true;
            yield 'hello';
        });
        self::assertNull($body->getSize());
        self::assertFalse($body->eof());
        self::assertFalse($body->isSeekable());
        self::assertFalse($body->isWritable());
        self::assertTrue($body->isReadable());
        self::assertSame(0, $body->tell());
        self::assertFalse($body->getMetadata('seekable'));
        self::assertSame('', $body->read(0));
        $body->close();
        self::assertTrue($body->eof());
        self::assertFalse($body->isReadable());
        self::assertFalse($started);
    }

    public function testFirstReadReturnsBeforeAdvancingProducerAndCloseRunsFinally(): void
    {
        $events = [];
        $body = new StreamingBody(static function () use (&$events): Generator {
            try {
                $events[] = 'start';
                yield 'first';
                $events[] = 'second';
                yield 'second';
            } finally {
                $events[] = 'close';
            }
        });
        self::assertSame('fi', $body->read(2));
        self::assertSame('rst', $body->read(8192));
        self::assertSame(['start'], $events);
        self::assertSame(5, $body->tell());
        $body->close();
        self::assertSame(['start', 'close'], $events);
    }

    public function testEmptyChunksAndEndOfStream(): void
    {
        $body = new StreamingBody(static fn(): array => ['', 'a', '', 'bc']);
        self::assertSame('a', $body->read(8192));
        self::assertSame('bc', $body->getContents());
        self::assertTrue($body->eof());
        self::assertSame(3, $body->tell());
        self::assertSame('', $body->read(1));
    }

    public function testProducerErrorPropagatesToEmitterAndCanBeClosed(): void
    {
        $closed = false;
        $body = new StreamingBody(static function () use (&$closed): Generator {
            try {
                yield 'first';
                throw new RuntimeException('producer failed');
            } finally {
                $closed = true;
            }
        });
        self::assertSame('first', $body->read(8192));
        try {
            $body->read(8192);
            self::fail('Producer failure must not become a successful EOF.');
        } catch (RuntimeException $error) {
            self::assertSame('producer failed', $error->getMessage());
        } finally {
            $body->close();
        }
        self::assertTrue($closed);
    }

    public function testHeadNeverStartsProducer(): void
    {
        $started = false;
        $app = new Hono();
        $app->get('/', static function (Context $context) use (&$started) {
            return Streaming::streamText($context, static function () use (&$started): Generator {
                $started = true;
                yield 'hello';
            });
        });
        $response = $app->request('/', ['method' => 'HEAD']);
        self::assertSame('', (string) $response->getBody());
        self::assertFalse($started);
    }

    public function testSseFramesMultilineDataAndMetadata(): void
    {
        $context = new Context(new HonoRequest(new Psr17Factory()->createServerRequest('GET', '/')));
        $response = Streaming::streamSSE($context, static fn(): array => [
            ['data' => "one\r\ntwo\rthree\n", 'event' => 'update', 'id' => '42', 'retry' => 1000],
            ['data' => ''],
        ]);
        self::assertSame('text/event-stream; charset=UTF-8', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            "event: update\nid: 42\nretry: 1000\ndata: one\ndata: two\ndata: three\ndata: \n\ndata: \n\n",
            (string) $response->getBody(),
        );
    }

    public function testSseRejectsMetadataLineInjection(): void
    {
        $context = new Context(new HonoRequest(new Psr17Factory()->createServerRequest('GET', '/')));
        $response = Streaming::streamSSE($context, static fn(): array => [[
            'data' => 'safe',
            'id' => "one\ndata: injected",
        ]]);
        $this->expectException(InvalidArgumentException::class);
        $response->getBody()->getContents();
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidSseEvents(): iterable
    {
        yield 'retry line injection' => [['data' => 'safe', 'retry' => "0\nid: injected"]];
        yield 'retry numeric string' => [['data' => 'safe', 'retry' => '1000']];
        yield 'retry float' => [['data' => 'safe', 'retry' => 1.5]];
        yield 'retry boolean' => [['data' => 'safe', 'retry' => false]];
        yield 'retry null' => [['data' => 'safe', 'retry' => null]];
        yield 'retry negative' => [['data' => 'safe', 'retry' => -1]];
        yield 'missing data' => [[]];
        yield 'data is not text' => [['data' => ['unsafe']]];
        yield 'event is not text' => [['data' => 'safe', 'event' => 42]];
        yield 'id is not text' => [['data' => 'safe', 'id' => false]];
    }

    /** @param array<string, mixed> $event */
    #[DataProvider('invalidSseEvents')]
    public function testSseRejectsMalformedRuntimeFields(array $event): void
    {
        $context = new Context(new HonoRequest(new Psr17Factory()->createServerRequest('GET', '/')));
        // @phpstan-ignore argument.type (Malformed runtime SSE fields deliberately violate the public PHPDoc.)
        $response = Streaming::streamSSE($context, static fn(): array => [$event]);
        $this->expectException(InvalidArgumentException::class);
        $response->getBody()->getContents();
    }

    public function testHelpersRespectContextStatusAndCallerContentType(): void
    {
        $context = new Context(new HonoRequest(new Psr17Factory()->createServerRequest('GET', '/')));
        $context->status(201);
        $response = Streaming::streamText($context, static fn(): array => ['hello'], headers: [
            'content-type' => 'application/custom',
        ]);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('application/custom', $response->getHeaderLine('Content-Type'));
    }

    public function testClosedStreamCannotBeRead(): void
    {
        $body = new StreamingBody(static fn(): array => ['hello']);
        self::assertNull($body->detach());
        $this->expectException(RuntimeException::class);
        $body->read(1);
    }
}
