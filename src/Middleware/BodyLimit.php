<?php

declare(strict_types=1);

namespace PhpHono\Middleware;

use Closure;
use InvalidArgumentException;
use PhpHono\Context;
use PhpHono\HttpException;
use Psr\Http\Message\ResponseInterface;

final class BodyLimit
{
    /** @var (Closure(Context): ResponseInterface)|null */
    private readonly ?Closure $onError;

    /** @param (callable(Context): ResponseInterface)|null $onError */
    public function __construct(
        private readonly int $maxBytes,
        ?callable $onError = null,
    ) {
        if ($maxBytes < 0) {
            throw new InvalidArgumentException('Body limit must not be negative');
        }

        $this->onError = $onError === null ? null : Closure::fromCallable($onError);
    }

    public function __invoke(Context $context, callable $next): ?ResponseInterface
    {
        $limit = min($this->maxBytes, $context->req->bodyStore()->limit());
        $request = $context->req->raw();

        try {
            if ($request->hasHeader('Content-Length') && !$request->hasHeader('Transfer-Encoding')) {
                $length = trim($request->getHeaderLine('Content-Length'));

                if (preg_match('/^[0-9]+$/D', $length) !== 1) {
                    throw new HttpException(400, 'Invalid Content-Length');
                }

                $length = ltrim($length, characters: '0');
                $maximum = (string) $limit;

                if (
                    strlen($length) > strlen($maximum)
                    || strlen($length) === strlen($maximum) && strcmp($length, $maximum) > 0
                ) {
                    throw new HttpException(413, 'Payload Too Large');
                }
            }

            $context->req->bodyStore()->bytes($limit);
        } catch (HttpException $exception) {
            if ($exception->status !== 413 || $this->onError === null) {
                throw $exception;
            }

            return ($this->onError)($context);
        }

        $next();

        return null;
    }
}
