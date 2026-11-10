<?php

declare(strict_types=1);

namespace PhpHono\Http;

use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

final readonly class ResponseFactory
{
    private ResponseFactoryInterface $responses;
    private StreamFactoryInterface $streams;

    public function __construct(?ResponseFactoryInterface $responses = null, ?StreamFactoryInterface $streams = null)
    {
        $defaults = new Psr17Factory();
        $this->responses = $responses ?? $defaults;
        $this->streams = $streams ?? $defaults;
    }

    /** @param array<string, string|list<string>> $headers */
    public function create(string|StreamInterface $body = '', int $status = 200, array $headers = []): ResponseInterface
    {
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException('HTTP status must be between 100 and 599.');
        }
        $response = $this->responses->createResponse($status);
        foreach ($headers as $name => $value) {
            self::validateHeader($name, $value);
            $response = $response->withHeader($name, $value);
        }
        return $response->withBody(is_string($body) ? $this->streams->createStream($body) : $body);
    }

    /** @param string|list<string> $value */
    public static function validateHeader(string $name, string|array $value): void
    {
        if (preg_match('/^[a-zA-Z0-9!#$%&\'*+.^_`|~-]+$/D', $name) !== 1) {
            throw new InvalidArgumentException('Invalid HTTP header name.');
        }
        foreach (is_array($value) ? $value : [$value] as $entry) {
            if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $entry) === 1) {
                throw new InvalidArgumentException('Invalid HTTP header value.');
            }
        }
    }

    public function emptyBody(): StreamInterface
    {
        return $this->streams->createStream();
    }
}
