<?php

declare(strict_types=1);

namespace PhpHono\Internal;

use PhpHono\HttpException;

/** @internal */
final class QueryParser
{
    /** @return array<array-key, non-empty-list<string>> */
    public static function parse(string $input): array
    {
        $result = [];

        foreach (explode('&', $input) as $pair) {
            if ($pair === '') {
                continue;
            }

            $parts = explode('=', $pair, limit: 2);
            $name = self::decode($parts[0], true);
            $value = self::decode($parts[1] ?? '', true);
            $result[$name][] = $value;
        }

        return $result;
    }

    public static function decode(string $value, bool $plusAsSpace = false): string
    {
        if (preg_match('/%(?![0-9a-fA-F]{2})/', $value) === 1) {
            throw new HttpException(400, 'Malformed percent encoding');
        }

        $decoded = rawurldecode($plusAsSpace ? str_replace('+', replace: ' ', subject: $value) : $value);

        if (preg_match('//u', $decoded) !== 1) {
            throw new HttpException(400, 'Invalid UTF-8');
        }

        return $decoded;
    }
}
