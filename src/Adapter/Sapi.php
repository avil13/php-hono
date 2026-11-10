<?php

declare(strict_types=1);

namespace PhpHono\Adapter;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use PhpHono\Http\ResponseFactory;
use PhpHono\HttpException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * This boundary maps PHP request metadata and upload failure codes into HTTP errors.
 *
 * @mago-expect lint:cyclomatic-complexity
 */
final class Sapi
{
    public static function request(): ServerRequestInterface
    {
        $target = $_SERVER['REQUEST_URI'] ?? '/';
        if (!is_string($target) || preg_match('/[\x00-\x20\x7F]/', $target) === 1) {
            throw new HttpException(400, 'Invalid request target.');
        }
        $path = explode('?', $target, limit: 2)[0];
        if (preg_match('/%(?![0-9a-fA-F]{2})/', $path) === 1 || preg_match('//u', rawurldecode($path)) !== 1) {
            throw new HttpException(400, 'Invalid request path encoding.');
        }
        self::checkPostLimit();
        $factory = new Psr17Factory();
        $creator = new ServerRequestCreator($factory, $factory, $factory, $factory);
        $server = $_SERVER;
        // Nyholm interprets X-Forwarded-Proto as trusted when constructing the URI.
        // Keep the header, but only SAPI transport metadata selects the scheme.
        unset($server['HTTP_X_FORWARDED_PROTO']);
        $server['REQUEST_METHOD'] ??= 'GET';
        $headers = ServerRequestCreator::getHeadersFromServer($_SERVER);
        $rawType = $_SERVER['CONTENT_TYPE'] ?? '';
        $contentType = is_string($rawType) ? strtolower(trim(explode(';', $rawType, limit: 2)[0])) : '';
        $parsed = $server['REQUEST_METHOD'] === 'POST'
        && in_array($contentType, ['application/x-www-form-urlencoded', 'multipart/form-data'], strict: true)
            ? $_POST
            : null;
        $input = fopen('php://input', mode: 'rb');
        if ($input === false) {
            throw new HttpException(500, 'Cannot open the request body.');
        }
        try {
            $request = $creator
                ->fromArrays($server, $headers, $_COOKIE, $_GET, $parsed, $_FILES, $input)
                ->withRequestTarget($target);
        } catch (Throwable $error) {
            fclose($input);
            throw $error;
        }
        self::checkUploads($request->getUploadedFiles());
        $type = strtolower(explode(';', $request->getHeaderLine('Content-Type'), limit: 2)[0]);
        if (
            $request->getMethod() === 'POST'
            && trim($type) === 'multipart/form-data'
            && filter_var(ini_get('enable_post_data_reading'), FILTER_VALIDATE_BOOL)
        ) {
            $request = $request->withAttribute('php_hono.multipart_parsed', true);
        }
        return $request;
    }

    public static function run(RequestHandlerInterface $app, ?SapiEmitter $emitter = null): void
    {
        try {
            $request = self::request();
            $response = $app->handle($request);
            $head = strtoupper($request->getMethod()) === 'HEAD';
        } catch (Throwable $error) {
            $status = $error instanceof HttpException ? $error->status : 500;
            $response = new ResponseFactory()->create(
                $status >= 500 ? 'Internal Server Error' : 'Invalid Request',
                $status,
                ['Content-Type' => 'text/plain; charset=UTF-8'],
            );
            $method = $_SERVER['REQUEST_METHOD'] ?? '';
            $head = is_string($method) && strtoupper($method) === 'HEAD';
            if ($status >= 500) {
                error_log('php-hono request failed: ' . $error::class);
            }
        }
        ($emitter ?? new SapiEmitter())->emit($response, $head);
    }

    private static function checkPostLimit(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return;
        }
        $limit = ini_parse_quantity((string) ini_get('post_max_size'));
        $length = $_SERVER['CONTENT_LENGTH'] ?? null;
        if ($limit > 0 && is_scalar($length) && is_numeric($length) && (float) $length > $limit) {
            throw new HttpException(413, 'Request exceeds PHP post_max_size.');
        }
    }

    /** @param array<mixed> $uploads */
    private static function checkUploads(array $uploads): void
    {
        foreach ($uploads as $upload) {
            if (is_array($upload)) {
                self::checkUploads($upload);
                continue;
            }
            if (!$upload instanceof UploadedFileInterface) {
                throw new HttpException(400, 'Invalid upload metadata.');
            }
            $status = match ($upload->getError()) {
                UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE => null,
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 413,
                UPLOAD_ERR_PARTIAL => 400,
                UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => 500,
                default => 400,
            };
            if ($status !== null) {
                throw new HttpException($status, 'PHP could not receive an uploaded file.');
            }
        }
    }
}
