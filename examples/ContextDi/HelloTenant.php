<?php

declare(strict_types=1);

namespace PhpHono\Examples\ContextDi;

use PhpHono\Context;
use Psr\Http\Message\ResponseInterface;

final readonly class HelloTenant
{
    public function __construct(
        private string $greeting,
    ) {}

    public function __invoke(Context $context): ResponseInterface
    {
        $tenant = $context->extension(TenantContext::class);
        $metadata = $context->extension(RequestMetadata::class);
        return $context->json([
            'message' => $this->greeting . $tenant->id,
            'requestId' => $metadata->traceId,
        ]);
    }
}
