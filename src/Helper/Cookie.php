<?php

declare(strict_types=1);

namespace PhpHono\Helper;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use PhpHono\Context;

/**
 * Cookie parsing and serialization share the explicit RFC attribute validation rules.
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class Cookie
{
    /** @return ($name is null ? array<array-key, string> : string|null) */
    public static function get(Context $context, ?string $name = null): string|array|null
    {
        $cookies = self::parse($context->req->raw()->getHeaderLine('Cookie'));

        return $name === null ? $cookies : $cookies[$name] ?? null;
    }

    /** @return array<array-key, string> */
    public static function parse(string $header): array
    {
        $cookies = [];

        foreach (explode(';', $header) as $pair) {
            $parts = explode('=', $pair, limit: 2);

            if (count($parts) !== 2) {
                continue;
            }

            $name = trim($parts[0], characters: " \t");

            if (
                preg_match('/^[\x21\x23-\x3A\x3C\x3E-\x5B\x5D-\x7E]+$/D', $name) !== 1
                || array_key_exists($name, $cookies)
            ) {
                continue;
            }

            $value = trim($parts[1], characters: " \t");

            if (strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
                $value = substr($value, offset: 1, length: -1);
            }

            if (preg_match('/^[\x20-\x21\x23-\x3A\x3C-\x5B\x5D-\x7E]*$/D', $value) !== 1) {
                continue;
            }

            $decoded = rawurldecode($value);
            $cookies[$name] =
                preg_match('/%(?![0-9a-fA-F]{2})/', $value) === 1 || preg_match('//u', $decoded) !== 1
                    ? $value
                    : $decoded;
        }

        return $cookies;
    }

    /** @param array{domain?: string, path?: string, expires?: DateTimeInterface, maxAge?: int, httpOnly?: bool, secure?: bool, sameSite?: string} $options */
    public static function set(Context $context, string $name, string $value, array $options = []): void
    {
        $context->header('Set-Cookie', self::serialize($name, $value, $options), true);
    }

    /** @param array{domain?: string, path?: string, expires?: DateTimeInterface, maxAge?: int, httpOnly?: bool, secure?: bool, sameSite?: string} $options */
    public static function delete(Context $context, string $name, array $options = []): void
    {
        self::set($context, $name, '', [...$options, 'maxAge' => 0, 'expires' => new DateTimeImmutable('@0')]);
    }

    /** @param array{domain?: string, path?: string, expires?: DateTimeInterface, maxAge?: int, httpOnly?: bool, secure?: bool, sameSite?: string} $options */
    public static function serialize(string $name, string $value, array $options = []): string
    {
        if (preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D', $name) !== 1) {
            throw new InvalidArgumentException('Invalid cookie name');
        }

        self::validateOptions($options);

        $path = $options['path'] ?? '/';
        $domain = $options['domain'] ?? null;
        $secure = $options['secure'] ?? false;
        $sameSite = array_key_exists('sameSite', $options) ? ucfirst(strtolower($options['sameSite'])) : null;

        if (!str_starts_with($path, '/') || preg_match('/[\x00-\x1f\x7f;]/', $path) === 1) {
            throw new InvalidArgumentException('Invalid cookie path');
        }

        if (
            $domain !== null
            && filter_var(ltrim($domain, characters: '.'), FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
        ) {
            throw new InvalidArgumentException('Invalid cookie domain');
        }

        if ($sameSite !== null && !in_array($sameSite, ['Strict', 'Lax', 'None'], strict: true)) {
            throw new InvalidArgumentException('Invalid SameSite value');
        }

        if ($sameSite === 'None' && !$secure) {
            throw new InvalidArgumentException('SameSite=None requires Secure');
        }

        if ((str_starts_with($name, '__Secure-') || str_starts_with($name, '__Host-')) && !$secure) {
            throw new InvalidArgumentException('Prefixed cookie requires Secure');
        }

        if (str_starts_with($name, '__Host-') && ($path !== '/' || $domain !== null)) {
            throw new InvalidArgumentException('__Host- cookies require Path=/ and no Domain');
        }

        $cookie = $name . '=' . rawurlencode($value);

        if (array_key_exists('maxAge', $options)) {
            $cookie .= '; Max-Age=' . $options['maxAge'];
        }

        if ($domain !== null) {
            $cookie .= '; Domain=' . $domain;
        }

        $cookie .= '; Path=' . $path;

        if (array_key_exists('expires', $options)) {
            $date = DateTimeImmutable::createFromInterface($options['expires'])->setTimezone(new DateTimeZone('GMT'));
            $cookie .= '; Expires=' . $date->format('D, d M Y H:i:s \G\M\T');
        }

        if ($options['httpOnly'] ?? false) {
            $cookie .= '; HttpOnly';
        }

        if ($secure) {
            $cookie .= '; Secure';
        }

        if ($sameSite !== null) {
            $cookie .= '; SameSite=' . $sameSite;
        }

        return $cookie;
    }

    /** @param array<string, mixed> $options */
    private static function validateOptions(array $options): void
    {
        foreach ($options as $option => $optionValue) {
            $valid = match ($option) {
                'domain', 'path', 'sameSite' => is_string($optionValue),
                'expires' => $optionValue instanceof DateTimeInterface,
                'maxAge' => is_int($optionValue) && $optionValue >= 0,
                'httpOnly', 'secure' => is_bool($optionValue),
                default => false,
            };

            if (!$valid) {
                throw new InvalidArgumentException('Invalid cookie option: ' . $option);
            }
        }
    }
}
