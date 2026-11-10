<?php

declare(strict_types=1);

namespace PhpHono\Helper;

use Generator;
use InvalidArgumentException;
use PhpHono\Context;
use PhpHono\Http\StreamingBody;
use Psr\Http\Message\ResponseInterface;

final class Streaming
{
    /**
     * @param callable(): iterable<string> $producer
     * @param array<string, string|list<string>> $headers
     */
    public static function stream(
        Context $context,
        callable $producer,
        ?int $status = null,
        array $headers = [],
    ): ResponseInterface {
        return $context->body(new StreamingBody($producer), $status, $headers);
    }

    /**
     * @param callable(): iterable<string> $producer
     * @param array<string, string|list<string>> $headers
     */
    public static function streamText(
        Context $context,
        callable $producer,
        ?int $status = null,
        array $headers = [],
    ): ResponseInterface {
        return self::stream($context, $producer, $status, self::defaults($headers, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]));
    }

    /**
     * @param callable(): iterable<array{data: string, event?: string, id?: string, retry?: int}> $producer
     * @param array<string, string|list<string>> $headers
     */
    public static function streamSSE(
        Context $context,
        callable $producer,
        ?int $status = null,
        array $headers = [],
    ): ResponseInterface {
        return self::stream(
            $context,
            static function () use ($producer): Generator {
                foreach ($producer() as $event) {
                    yield self::event($event);
                }
            },
            $status,
            self::defaults($headers, [
                'Content-Type' => 'text/event-stream; charset=UTF-8',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
            ]),
        );
    }

    /** @param array<string, mixed> $event */
    private static function event(array $event): string
    {
        if (!array_key_exists('data', $event) || !is_string($event['data'])) {
            throw new InvalidArgumentException('SSE data must be a string.');
        }
        $message = '';
        foreach (['event', 'id'] as $field) {
            if (array_key_exists($field, $event)) {
                if (!is_string($event[$field]) || strpbrk($event[$field], characters: "\r\n\0") !== false) {
                    throw new InvalidArgumentException(
                        'SSE event and id must contain a single line without NUL bytes.',
                    );
                }
                $message .= $field . ': ' . $event[$field] . "\n";
            }
        }
        if (array_key_exists('retry', $event)) {
            if (!is_int($event['retry']) || $event['retry'] < 0) {
                throw new InvalidArgumentException('SSE retry must be a non-negative number of milliseconds.');
            }
            $message .= 'retry: ' . $event['retry'] . "\n";
        }
        foreach (explode("\n", str_replace(["\r\n", "\r"], replace: "\n", subject: $event['data'])) as $line) {
            $message .= 'data: ' . $line . "\n";
        }
        return $message . "\n";
    }

    /**
     * @param array<string, string|list<string>> $headers
     * @param array<string, string> $defaults
     * @return array<string, string|list<string>>
     */
    private static function defaults(array $headers, array $defaults): array
    {
        $existing = array_change_key_case($headers);
        foreach ($defaults as $name => $value) {
            if (!array_key_exists(strtolower($name), $existing)) {
                $headers[$name] = $value;
            }
        }
        return $headers;
    }
}
