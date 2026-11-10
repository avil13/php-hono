<?php

declare(strict_types=1);

namespace PhpHono\Internal;

use InvalidArgumentException;
use PhpHono\HttpException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;
use WeakMap;

/** @internal */
final class BodyStore
{
    public const int DEFAULT_LIMIT = 8 * 1024 * 1024;

    private ?string $contents = null;

    private ?Throwable $failure = null;

    /** @var WeakMap<StreamInterface, true> */
    private WeakMap $replays;

    public function __construct(
        private readonly StreamInterface $source,
        private int $limit = self::DEFAULT_LIMIT,
    ) {
        if ($limit < 0) {
            throw new InvalidArgumentException('Body limit must not be negative');
        }

        $this->replays = new WeakMap();
    }

    public function limit(): int
    {
        return $this->limit;
    }

    public function owns(StreamInterface $stream): bool
    {
        return $stream === $this->source || $this->replays->offsetExists($stream);
    }

    public function bytes(?int $lowerLimit = null): string
    {
        if ($lowerLimit !== null) {
            if ($lowerLimit < 0) {
                throw new InvalidArgumentException('Body limit must not be negative');
            }

            $this->limit = min($this->limit, $lowerLimit);
        }

        if ($this->failure !== null) {
            throw $this->failure;
        }

        try {
            $cached = $this->contents;
            if ($cached !== null) {
                $this->checkLength(strlen($cached));

                return $cached;
            }

            if (!$this->source->isReadable()) {
                throw new RuntimeException('Request body stream is not readable');
            }

            if ($this->source->isSeekable()) {
                $this->source->rewind();
            }

            $contents = '';
            $ended = $this->source->eof();

            while (!$ended) {
                $remaining = $this->limit - strlen($contents);
                $chunk = $this->source->read($remaining < 8192 ? $remaining + 1 : 8192);
                $ended = $this->source->eof();

                if ($chunk === '' && !$ended) {
                    throw new RuntimeException('Request body stream made no progress');
                }

                $contents .= $chunk;
                $this->checkLength(strlen($contents));
            }

            $this->contents = $contents;

            return $contents;
        } catch (Throwable $exception) {
            $this->failure = $exception;

            throw $exception;
        }
    }

    public function replay(): StreamInterface
    {
        $stream = new ReplayStream($this->bytes());
        $this->replays[$stream] = true;

        return $stream;
    }

    private function checkLength(int $length): void
    {
        if ($length > $this->limit) {
            throw new HttpException(413, 'Payload Too Large');
        }
    }
}
