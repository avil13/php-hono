<?php

declare(strict_types=1);

namespace PhpHono\Middleware;

use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PhpHono\Context;
use PhpHono\Http\StreamingBody;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;
use Throwable;

/**
 * Negotiation, representation headers and stream ownership are one compression transaction.
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final readonly class Compress
{
    private StreamFactoryInterface $streams;

    public function __construct(
        private int $minimumLength = 1024,
        private int $level = -1,
        ?StreamFactoryInterface $streams = null,
    ) {
        if ($minimumLength < 0 || $level < -1 || $level > 9) {
            throw new InvalidArgumentException('Invalid gzip compression options.');
        }
        $this->streams = $streams ?? new Psr17Factory();
    }

    /**
     * The stream transaction keeps cleanup beside every transformation and header change.
     * @param callable(): void $next
     * @mago-expect lint:halstead
     */
    public function __invoke(Context $context, callable $next): void
    {
        $next();
        $response = $context->response();
        if ($response === null || $response->hasHeader('Content-Encoding')) {
            return;
        }
        $vary = array_map('trim', explode(',', $response->getHeaderLine('Vary')));
        if (
            !in_array('*', $vary, strict: true)
            && !in_array('accept-encoding', array_map('strtolower', $vary), strict: true)
        ) {
            $response = $response->withHeader('Vary', implode(', ', array_filter(
                [...$vary, 'Accept-Encoding'],
                static fn(string $item): bool => $item !== '',
            )));
        }
        $context->setResponseExact($response);
        $body = $response->getBody();
        if ($response->getStatusCode() < 200 || in_array($response->getStatusCode(), [204, 205, 304], strict: true)) {
            return;
        }
        [$acceptsGzip, $acceptsIdentity] = self::negotiate($context->req->header('Accept-Encoding') ?? '');
        $size = $body->getSize();
        $canCompress =
            !$body instanceof StreamingBody
            && $response->getStatusCode() !== 206
            && !in_array(
                'no-transform',
                array_map('trim', explode(',', strtolower($response->getHeaderLine('Cache-Control')))),
                strict: true,
            )
            && $body->isReadable()
            && $body->isSeekable()
            && function_exists('deflate_init');
        if (!$acceptsGzip || !$canCompress) {
            if (!$acceptsIdentity) {
                $context->setResponseExact($context->body('', 406, ['Vary' => $response->getHeaderLine('Vary')]));
            }
            return;
        }
        if (
            $acceptsIdentity
            && (strtoupper($context->req->method()) === 'HEAD' || $size !== null && $size < $this->minimumLength)
        ) {
            return;
        }
        if (strtoupper($context->req->method()) === 'HEAD') {
            $response = $response->withHeader('Content-Encoding', 'gzip')->withoutHeader('Content-Length');
            $tag = $response->getHeaderLine('ETag');
            if ($tag !== '' && !str_starts_with($tag, 'W/')) {
                $response = $response->withHeader('ETag', 'W/' . $tag);
            }
            $context->setResponseExact($response);
            return;
        }

        $position = $body->tell();
        $encoder = deflate_init(ZLIB_ENCODING_GZIP, ['level' => $this->level]);
        $resource = fopen('php://temp/maxmemory:2097152', mode: 'w+b');
        if ($encoder === false || $resource === false) {
            if (is_resource($resource)) {
                fclose($resource);
            }
            throw new RuntimeException('Unable to initialize gzip compression.');
        }
        try {
            $body->rewind();
            do {
                $chunk = $body->read(8192);
                if ($chunk === '' && !$body->eof()) {
                    throw new RuntimeException('Response stream made no progress during compression.');
                }
                $encoded = deflate_add($encoder, $chunk, ZLIB_NO_FLUSH);
                if ($encoded === false || fwrite($resource, $encoded) !== strlen($encoded)) {
                    throw new RuntimeException('Unable to write compressed response.');
                }
            } while (!$body->eof());
            $tail = deflate_add($encoder, data: '', flush_mode: ZLIB_FINISH);
            if ($tail === false || fwrite($resource, $tail) !== strlen($tail)) {
                throw new RuntimeException('Unable to finish compressed response.');
            }
            rewind($resource);
            $compressed = $this->streams->createStreamFromResource($resource);
            $response = $response
                ->withBody($compressed)
                ->withHeader('Content-Encoding', 'gzip')
                ->withoutHeader('Content-Length');
            if (($compressedSize = $compressed->getSize()) !== null) {
                $response = $response->withHeader('Content-Length', (string) $compressedSize);
            }
            $tag = $response->getHeaderLine('ETag');
            if ($tag !== '' && !str_starts_with($tag, 'W/')) {
                $response = $response->withHeader('ETag', 'W/' . $tag);
            }
            $context->setResponseExact($response);
        } catch (Throwable $error) {
            if (is_resource($resource)) {
                fclose($resource);
            }
            throw $error;
        } finally {
            $body->seek($position);
        }
    }

    /** @return array{bool, bool} */
    private static function negotiate(string $header): array
    {
        /** @var array<string, float> $qualities */
        $qualities = [];
        foreach (explode(',', strtolower($header)) as $entry) {
            $parts = array_map('trim', explode(';', $entry));
            $coding = $parts[0];
            if ($coding === '') {
                continue;
            }
            $quality = 1.0;
            foreach (array_slice($parts, offset: 1) as $parameter) {
                if (preg_match('/\Aq=(0(?:\.[0-9]{0,3})?|1(?:\.0{0,3})?)\z/D', $parameter, $match) !== 1) {
                    $quality = 0.0;
                    break;
                }
                $quality = (float) $match[1];
            }
            $qualities[$coding] = max($qualities[$coding] ?? 0.0, $quality);
        }
        $gzip = $qualities['gzip'] ?? $qualities['*'] ?? 0.0;
        $identity = $qualities['identity'] ?? 0.0;

        $identityAllowed = array_key_exists('identity', $qualities) ? $identity > 0.0 : ($qualities['*'] ?? 1.0) > 0.0;

        return [$gzip > 0.0 && $gzip >= $identity, $identityAllowed];
    }
}
