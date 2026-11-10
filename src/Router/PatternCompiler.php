<?php

declare(strict_types=1);

namespace PhpHono\Router;

use InvalidArgumentException;

/**
 * One compiler owns the deliberately limited route grammar and its validation.
 * @internal
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class PatternCompiler
{
    /** @return array{string, list<string>} */
    public static function compile(string $path, int $matchLimit, int $depthLimit): array
    {
        if ($path === '*' || $path === '/*') {
            return [self::delimit('/.*', $matchLimit, $depthLimit), []];
        }

        if (
            !str_starts_with($path, '/')
            || preg_match('/[\x00-\x20\x7f#]/', $path) === 1
            || preg_match('//u', $path) !== 1
        ) {
            throw new InvalidArgumentException(
                'Route paths must be absolute valid UTF-8 paths without control characters.',
            );
        }

        $segments = explode('/', substr($path, offset: 1));
        $last = count($segments) - 1;
        $pattern = '';
        $names = [];

        foreach ($segments as $index => $segment) {
            if ($segment === '*') {
                if ($index !== $last) {
                    throw new InvalidArgumentException('Only a terminal wildcard is supported.');
                }

                $pattern .= '(?:/.*)?';
                continue;
            }

            if (str_starts_with($segment, ':')) {
                if (preg_match('/\A:([A-Za-z_][A-Za-z0-9_]*)(?:\{(.+)\})?(\?)?\z/sD', $segment, $parts) !== 1) {
                    throw new InvalidArgumentException('Unsupported route parameter: ' . $segment);
                }

                $optional = array_key_exists(3, $parts);
                if ($optional && $index !== $last) {
                    throw new InvalidArgumentException('Only the terminal parameter may be optional.');
                }

                $expression = ($parts[2] ?? '') !== '' ? self::segmentExpression($parts[2]) : '[^/]+';
                $names[] = $parts[1];
                $capture = '/(' . $expression . ')';
                if ($optional) {
                    $capture = $pattern === '' ? '/(?:(' . $expression . '))?' : '(?:' . $capture . ')?';
                }
                $pattern .= $capture;
                continue;
            }

            if (strpbrk($segment, characters: '*?:{}') !== false) {
                throw new InvalidArgumentException('Unsupported route syntax: ' . $segment);
            }

            $pattern .= '/' . preg_quote($segment, delimiter: '~');
        }

        $compiled = self::delimit($pattern, $matchLimit, $depthLimit);
        // Invalid user-supplied grammar is converted to an explicit registration exception.
        // @mago-expect lint:no-error-control-operator
        if (@preg_match($compiled, subject: '') === false) {
            throw new InvalidArgumentException('Invalid route regular expression.');
        }

        return [$compiled, $names];
    }

    private static function delimit(string $expression, int $matchLimit, int $depthLimit): string
    {
        return (
            '~(*NO_JIT)(*LIMIT_MATCH=' . $matchLimit . ')(*LIMIT_DEPTH=' . $depthLimit . ')\A' . $expression . '\z~uD'
        );
    }

    /**
     * Token validation stays together so no alternative grammar bypasses its restrictions.
     * @mago-expect lint:halstead
     */
    private static function segmentExpression(string $expression): string
    {
        $length = strlen($expression);
        $result = '';

        for ($index = 0; $index < $length; $index++) {
            $character = $expression[$index];

            if ($character === '\\') {
                $next = $expression[++$index] ?? '';
                if ($next === '' || !str_contains('dwstn\\.[]{}()+*?^$|-', $next)) {
                    throw new InvalidArgumentException('Unsupported escape in route regular expression.');
                }

                $result .= '\\' . $next;
                continue;
            }

            if ($character === '[') {
                $end = $index + 1;
                if (($expression[$end] ?? '') === '^') {
                    $end++;
                }
                if (($expression[$end] ?? '') === ']') {
                    $end++;
                }
                for (; $end < $length; $end++) {
                    if ($expression[$end] === '\\') {
                        $escaped = $expression[++$end] ?? '';
                        if ($escaped === '' || !str_contains('dwstn\\[]^-', $escaped)) {
                            throw new InvalidArgumentException('Unsupported character class escape.');
                        }
                    } elseif ($expression[$end] === ']') {
                        break;
                    }
                }

                $class = str_replace('~', replace: '\\x7e', subject: substr($expression, $index, $end - $index + 1));
                // Invalid character classes are rejected below, without leaking PCRE warnings.
                // @mago-expect lint:no-error-control-operator
                $slashMatch = @preg_match('~' . $class . '~u', subject: '/');
                if ($end >= $length || $slashMatch !== 0) {
                    throw new InvalidArgumentException(
                        'Route character classes must be valid and cannot match a slash.',
                    );
                }

                $result .= $class;
                $index = $end;
                continue;
            }

            if ($character === '(') {
                if (substr($expression, $index, length: 3) !== '(?:') {
                    throw new InvalidArgumentException(
                        'Only non-capturing groups are supported in route regular expressions.',
                    );
                }
                $result .= '(?:';
                $index += 2;
                continue;
            }

            if ($character === '{') {
                if (preg_match('/\A\{[0-9]+(?:,[0-9]*)?\}/', substr($expression, $index), $quantifier) !== 1) {
                    throw new InvalidArgumentException('Only numeric quantifiers are supported.');
                }
                $result .= $quantifier[0];
                $index += strlen($quantifier[0]) - 1;
                continue;
            }

            if (str_contains('/.^$}:', $character)) {
                throw new InvalidArgumentException('Route regular expressions must match within one segment.');
            }

            $result .= str_contains(')|+*?', $character) ? $character : preg_quote($character, delimiter: '~');
        }

        return $result;
    }
}
