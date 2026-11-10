<?php

declare(strict_types=1);

namespace PhpHono\Http;

use Closure;
use Generator;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

/**
 * A single-use response stream whose producer starts only when bytes are read.
 * PSR-7 requires the complete stream interface; lazy iteration adds its state transitions.
 *
 * @mago-expect lint:too-many-methods
 * @mago-expect lint:cyclomatic-complexity
 */
final class StreamingBody implements StreamInterface
{
    /** @var (Closure(): iterable<string>)|null */
    private ?Closure $producer;
    /** @var Generator<mixed, mixed>|null */
    private ?Generator $iterator = null;
    private string $buffer = '';
    private int $position = 0;
    private bool $advance = false;
    private bool $finished = false;
    private bool $closed = false;

    /** @param callable(): iterable<string> $producer */
    public function __construct(callable $producer)
    {
        $this->producer = Closure::fromCallable($producer);
    }

    public function __toString(): string
    {
        try {
            return $this->getContents();
        } catch (Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        $this->closed = true;
        $this->finished = true;
        $this->buffer = '';
        $this->producer = null;
        $this->iterator = null;
    }

    public function detach(): mixed
    {
        $this->close();
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        $this->assertOpen();
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->finished && $this->buffer === '';
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('Streaming responses cannot be seeked.');
    }

    public function rewind(): void
    {
        throw new RuntimeException('Streaming responses cannot be rewound.');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('Streaming responses are read-only.');
    }

    public function isReadable(): bool
    {
        return !$this->closed;
    }

    public function read(int $length): string
    {
        $this->assertOpen();
        if ($length < 0) {
            throw new InvalidArgumentException('Read length cannot be negative.');
        }
        if ($length === 0 || $this->eof()) {
            return '';
        }
        if ($this->buffer === '') {
            $this->nextChunk();
        }
        $chunk = substr($this->buffer, offset: 0, length: $length);
        $this->buffer = substr($this->buffer, strlen($chunk));
        $this->position += strlen($chunk);
        return $chunk;
    }

    public function getContents(): string
    {
        $this->assertOpen();
        $contents = '';
        while (!$this->eof()) {
            $contents .= $this->read(8192);
        }
        return $contents;
    }

    public function getMetadata(?string $key = null): mixed
    {
        $metadata = ['seekable' => false, 'eof' => $this->eof(), 'mode' => 'r'];
        return $key === null ? $metadata : $metadata[$key] ?? null;
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new RuntimeException('Streaming response is closed.');
        }
    }

    private function nextChunk(): void
    {
        if ($this->iterator === null) {
            $producer = $this->producer;
            if ($producer === null) {
                throw new RuntimeException('Streaming producer is unavailable.');
            }
            $this->producer = null;
            $this->iterator = (static function () use ($producer): Generator {
                yield from $producer();
            })();
        }
        while (true) {
            if ($this->advance) {
                $this->iterator->next();
            }
            if (!$this->iterator->valid()) {
                $this->finished = true;
                $this->iterator = null;
                return;
            }
            $chunk = $this->iterator->current();
            $this->advance = true;
            if (!is_string($chunk)) {
                throw new RuntimeException('Streaming producers must yield strings.');
            }
            if ($chunk !== '') {
                $this->buffer = $chunk;
                return;
            }
        }
    }
}
