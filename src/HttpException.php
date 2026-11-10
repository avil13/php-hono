<?php

declare(strict_types=1);

namespace PhpHono;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

final class HttpException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message = '',
        public readonly ?ResponseInterface $response = null,
        ?Throwable $previous = null,
    ) {
        if ($status < 400 || $status > 599) {
            throw new InvalidArgumentException('HTTP exception status must be between 400 and 599.');
        }
        parent::__construct($message, $status, $previous);
    }
}
