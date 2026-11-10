<?php

declare(strict_types=1);

namespace PhpHono\Internal;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * A read-only view of bytes already checked by BodyStore.
 *
 * PSR-7 requires all of these stream methods, including unsupported mutations.
 *
 * @mago-expect lint:too-many-methods
 * @internal
 */
final class ReplayStream implements StreamInterface
{
    private int $position = 0;

    public function __construct(
        private ?string $contents,
    ) {}

    public function __toString(): string
    {
        $contents = $this->contents ?? '';
        $this->position = strlen($contents);

        return $contents;
    }

    public function close(): void
    {
        $this->contents = null;
        $this->position = 0;
    }

    public function detach(): mixed
    {
        // The buffer has no underlying PHP resource to transfer.
        $this->close();

        return null;
    }

    public function getSize(): ?int
    {
        return $this->contents === null ? null : strlen($this->contents);
    }

    public function tell(): int
    {
        $this->attachedContents();

        return $this->position;
    }

    public function eof(): bool
    {
        return $this->position >= strlen($this->attachedContents());
    }

    public function isSeekable(): bool
    {
        return $this->contents !== null;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $contents = $this->attachedContents();
        $base = match ($whence) {
            SEEK_SET => 0,
            SEEK_CUR => $this->position,
            SEEK_END => strlen($contents),
            default => throw new RuntimeException('Invalid seek mode'),
        };
        if ($offset < -$base || $offset > (PHP_INT_MAX - $base)) {
            throw new RuntimeException('Invalid stream position');
        }

        $this->position = $base + $offset;
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('Request replay streams are read-only; replace the body with withBody()');
    }

    public function isReadable(): bool
    {
        return $this->contents !== null;
    }

    public function read(int $length): string
    {
        $contents = $this->attachedContents();

        if ($length < 0) {
            throw new RuntimeException('Read length must not be negative');
        }

        $result = substr($contents, $this->position, $length);
        $this->position += strlen($result);

        return $result;
    }

    public function getContents(): string
    {
        return $this->read(strlen($this->attachedContents()));
    }

    public function getMetadata(?string $key = null): mixed
    {
        $metadata = $this->contents === null ? [] : ['seekable' => true, 'mode' => 'rb'];

        return $key === null ? $metadata : $metadata[$key] ?? null;
    }

    private function attachedContents(): string
    {
        return $this->contents ?? throw new RuntimeException('Stream is detached');
    }
}
