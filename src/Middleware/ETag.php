<?php

declare(strict_types=1);

namespace PhpHono\Middleware;

use PhpHono\Context;
use PhpHono\Http\StreamingBody;
use RuntimeException;

/**
 * Representation hashing and conditional responses form one HTTP middleware contract.
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class ETag
{
    public function __construct(
        private bool $weak = false,
    ) {}

    /** @param callable(): void $next */
    public function __invoke(Context $context, callable $next): void
    {
        $next();
        $response = $context->response();
        if (
            $response === null
            || !in_array(strtoupper($context->req->method()), ['GET', 'HEAD'], strict: true)
            || $response->getStatusCode() < 200
            || $response->getStatusCode() >= 300
            || in_array($response->getStatusCode(), [204, 205, 206], strict: true)
        ) {
            return;
        }
        $body = $response->getBody();
        if ($body instanceof StreamingBody || !$body->isSeekable() || !$body->isReadable()) {
            return;
        }
        $tag = $response->getHeaderLine('ETag');
        if ($tag === '') {
            if (strtoupper($context->req->method()) === 'HEAD') {
                return;
            }
            $position = $body->tell();
            $hash = hash_init('sha256');
            try {
                $body->rewind();
                do {
                    $chunk = $body->read(8192);
                    if ($chunk === '' && !$body->eof()) {
                        throw new RuntimeException('Response stream made no progress while computing ETag.');
                    }
                    hash_update($hash, $chunk);
                } while (!$body->eof());
            } finally {
                $body->seek($position);
            }
            $tag = ($this->weak ? 'W/' : '') . '"' . hash_final($hash) . '"';
            $response = $response->withHeader('ETag', $tag);
        }

        if (self::matches($tag, $context->req->header('If-None-Match') ?? '')) {
            $response = $response->withStatus(304)->withBody($context->body()->getBody());
            foreach (['Content-Type', 'Content-Length', 'Content-Encoding', 'Transfer-Encoding'] as $header) {
                $response = $response->withoutHeader($header);
            }
        }
        $context->setResponseExact($response);
    }

    private static function matches(string $tag, string $condition): bool
    {
        $condition = trim($condition);
        if ($condition === '*') {
            return true;
        }
        $tagPattern = '(?:W/)?"[^"\x00-\x20\x7f]*"';
        if (preg_match('~\A' . $tagPattern . '(?:[ \t]*,[ \t]*' . $tagPattern . ')*\z~D', $condition) !== 1) {
            return false;
        }
        preg_match_all('~' . $tagPattern . '~', $condition, $tokens);
        $value = str_starts_with($tag, 'W/') ? substr($tag, offset: 2) : $tag;
        foreach ($tokens[0] as $candidate) {
            if ((str_starts_with($candidate, 'W/') ? substr($candidate, offset: 2) : $candidate) === $value) {
                return true;
            }
        }

        return false;
    }
}
