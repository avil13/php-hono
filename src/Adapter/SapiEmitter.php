<?php

declare(strict_types=1);

namespace PhpHono\Adapter;

use Closure;
use LogicException;
use PhpHono\Http\ResponseFactory;
use PhpHono\Http\StreamingBody;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Emission owns header, body, disconnect and cleanup error boundaries together.
 *
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class SapiEmitter
{
    /** @var (Closure(Throwable): void)|null */
    private ?Closure $onError;

    /** @param (callable(Throwable): void)|null $onError */
    public function __construct(?callable $onError = null)
    {
        $this->onError = $onError === null ? null : Closure::fromCallable($onError);
    }

    public function emit(ResponseInterface $response, bool $head = false): void
    {
        $status = $response->getStatusCode();
        if ($status < 200 || $status === 204) {
            $response = $response->withoutHeader('Content-Length')->withoutHeader('Transfer-Encoding');
        } elseif ($status === 205) {
            $response = $response->withoutHeader('Transfer-Encoding')->withHeader('Content-Length', '0');
        }
        $body = $response->getBody();
        $previousIgnoreAbort = ignore_user_abort(true);
        try {
            if (headers_sent()) {
                throw new LogicException('Cannot emit a response after headers have been sent.');
            }
            http_response_code($response->getStatusCode());
            foreach ($response->getHeaders() as $name => $values) {
                ResponseFactory::validateHeader($name, array_values($values));
                foreach ($values as $value) {
                    header($name . ': ' . $value, replace: false);
                }
            }
            if (
                $head
                || $response->getStatusCode() < 200
                || in_array($response->getStatusCode(), [204, 205, 304], strict: true)
            ) {
                return;
            }
            if ($body->isSeekable()) {
                $body->rewind();
            }
            $ended = $body->eof();
            while (!$ended && connection_aborted() === 0) {
                $chunk = $body->read(8192);
                $ended = $body->eof();
                if ($chunk === '' && !$ended) {
                    throw new RuntimeException('Response stream made no progress during emission.');
                }
                echo $chunk;
                if ($body instanceof StreamingBody) {
                    $this->flush();
                }
            }
        } catch (Throwable $error) {
            $this->report($error);
        } finally {
            ignore_user_abort($previousIgnoreAbort !== 0);
            try {
                $body->close();
            } catch (Throwable $error) {
                $this->report($error);
            }
        }
    }

    private function flush(): void
    {
        $status = ob_get_status();
        $flags = $status['flags'] ?? 0;
        if (is_int($flags) && ($flags & PHP_OUTPUT_HANDLER_FLUSHABLE) !== 0) {
            ob_flush();
        }
        flush();
    }

    private function report(Throwable $error): void
    {
        if ($this->onError !== null) {
            try {
                ($this->onError)($error);
                return;
            } catch (Throwable $reportingError) {
                error_log('php-hono emission error reporter failed: ' . $reportingError::class);
            }
        }
        error_log('php-hono response emission failed: ' . $error::class);
    }
}
