<?php

declare(strict_types=1);

namespace PhpHono\Examples\ContextDi;

use Closure;
use PhpHono\Context;

final readonly class AttachTenant
{
    /** @param Closure(): TenantContext $create */
    public function __construct(
        private Closure $create,
    ) {}

    public function __invoke(Context $context, callable $next): void
    {
        // The factory runs once per request, while this middleware instance is shared.
        $context->set(TenantContext::class, ($this->create)());
        $next();
    }
}
