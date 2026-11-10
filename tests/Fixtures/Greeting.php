<?php

declare(strict_types=1);

namespace PhpHono\Tests\Fixtures;

use PhpHono\Context;
use Psr\Http\Message\ResponseInterface;

final readonly class Greeting
{
    public function __construct(
        private string $prefix,
    ) {}

    public function __invoke(Context $c): ResponseInterface
    {
        return $c->text($this->prefix . $c->extension(Identity::class)->name());
    }
}
