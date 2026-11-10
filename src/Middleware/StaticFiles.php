<?php

declare(strict_types=1);

namespace PhpHono\Middleware;

use finfo;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PhpHono\Context;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;
use Throwable;

/**
 * Path containment and file ownership checks must remain coupled to the same root.
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class StaticFiles
{
    private string $root;
    private string $prefix;
    private StreamFactoryInterface $streams;
    private finfo $mimeTypes;

    public function __construct(string $root, string $prefix = '/', ?StreamFactoryInterface $streams = null)
    {
        $canonical = realpath($root);
        if ($canonical === false || !is_dir($canonical)) {
            throw new InvalidArgumentException('Static file root must be an existing directory.');
        }
        if (
            !str_starts_with($prefix, '/')
            || strpbrk($prefix, characters: '?\\#') !== false
            || preg_match('/[\x00-\x20\x7f]/', $prefix) === 1
        ) {
            throw new InvalidArgumentException('Static URL prefix must be an absolute literal path.');
        }
        if (!class_exists(finfo::class)) {
            throw new RuntimeException('Static files require ext-fileinfo.');
        }
        $this->root = rtrim($canonical, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $this->prefix = $prefix === '/' ? '' : rtrim($prefix, characters: '/');
        $this->streams = $streams ?? new Psr17Factory();
        $this->mimeTypes = new finfo(FILEINFO_MIME_TYPE);
    }

    /**
     * Keep the containment checks next to opening and validating the same file.
     * @param callable(): void $next
     * @mago-expect lint:halstead
     */
    public function __invoke(Context $context, callable $next): ?ResponseInterface
    {
        $path = $context->req->path();
        if (
            !in_array(strtoupper($context->req->method()), ['GET', 'HEAD'], strict: true)
            || $this->prefix !== '' && $path !== $this->prefix && !str_starts_with($path, $this->prefix . '/')
        ) {
            $next();
            return null;
        }
        $relative = substr($path, strlen($this->prefix));
        if (preg_match('/%(?:2f|5c)/i', $relative) === 1 || str_contains($relative, '\\')) {
            return $context->text('Forbidden', 403);
        }
        $decoded = rawurldecode($relative);
        if (
            str_contains($decoded, "\0")
            || in_array('..', explode('/', $decoded), strict: true)
            || in_array('.', explode('/', $decoded), strict: true)
        ) {
            return $context->text('Forbidden', 403);
        }
        $candidate = realpath($this->root . ltrim($decoded, characters: '/'));
        if (
            $candidate !== false
            && !str_starts_with($candidate . (is_dir($candidate) ? DIRECTORY_SEPARATOR : ''), $this->root)
        ) {
            return $context->text('Forbidden', 403);
        }
        if ($candidate === false || !is_file($candidate) || !is_readable($candidate)) {
            $next();
            return null;
        }
        // A concurrently removed file is an ordinary miss; do not expose its server path.
        // @mago-expect lint:no-error-control-operator
        $info = @stat($candidate);
        if ($info === false) {
            $next();
            return null;
        }
        // A concurrent change may prevent MIME detection; the fallback below is explicit.
        // @mago-expect lint:no-error-control-operator
        $mime = @$this->mimeTypes->file($candidate);
        $headers = [
            'Content-Type' => $mime === false ? 'application/octet-stream' : $mime,
            'Content-Length' => (string) $info['size'],
            'Last-Modified' => gmdate('D, d M Y H:i:s', $info['mtime']) . ' GMT',
        ];
        if (strtoupper($context->req->method()) === 'HEAD') {
            return $context->body('', 200, $headers);
        }
        // The checked failure becomes a generic application error without filesystem details.
        // @mago-expect lint:no-error-control-operator
        $resource = @fopen($candidate, mode: 'rb');
        if ($resource === false) {
            throw new RuntimeException('Unable to open static response body.');
        }
        try {
            $opened = fstat($resource);
            if (
                $opened === false
                || realpath($candidate) !== $candidate
                || $opened['ino'] !== $info['ino']
                || $opened['dev'] !== $info['dev']
            ) {
                throw new RuntimeException('Static file changed while opening.');
            }
            $headers['Content-Length'] = (string) $opened['size'];
            $headers['Last-Modified'] = gmdate('D, d M Y H:i:s', $opened['mtime']) . ' GMT';
            $body = $this->streams->createStreamFromResource($resource);
            return $context->body($body, 200, $headers);
        } catch (Throwable $error) {
            if (is_resource($resource)) {
                fclose($resource);
            }
            throw $error;
        }
    }
}
