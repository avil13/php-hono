<?php

declare(strict_types=1);

namespace PhpHono\Helper;

final class Html
{
    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, encoding: 'UTF-8');
    }
}
