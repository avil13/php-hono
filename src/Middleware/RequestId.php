<?php

declare(strict_types=1);

namespace PhpHono\Middleware;

use InvalidArgumentException;
use PhpHono\Context;
use PhpHono\Http\ResponseFactory;

final readonly class RequestId
{
    public function __construct(
        private string $headerName = 'X-Request-Id',
        private string $contextKey = 'requestId',
    ) {
        ResponseFactory::validateHeader($headerName, '');

        if ($contextKey === '') {
            throw new InvalidArgumentException('Request ID context key must not be empty');
        }
    }

    public function __invoke(Context $context, callable $next): void
    {
        $id = $context->req->header($this->headerName);

        if ($id === null || preg_match('/^[A-Za-z0-9_=-]{1,255}$/D', $id) !== 1) {
            $id = bin2hex(random_bytes(16));
        }

        $context->set($this->contextKey, $id);
        $context->header($this->headerName, $id);
        $next();
        $context->header($this->headerName, $id);
    }
}
