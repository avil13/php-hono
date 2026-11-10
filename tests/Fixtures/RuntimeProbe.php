<?php

declare(strict_types=1);

namespace PhpHono\Tests\Fixtures;

use LogicException;
use PhpHono\Context;
use Psr\Http\Message\ResponseInterface;

final readonly class RuntimeProbe
{
    public function __construct(
        private string $prefix,
    ) {}

    public function __invoke(Context $context): ResponseInterface
    {
        $identity = $context->extension(Identity::class);
        $scope = $context->extension(RuntimeScope::class);
        if ($scope->closed) {
            throw new LogicException('A closed request scope was reused.');
        }
        ++$scope->touches;
        return $context->json([
            'user' => $identity->name(),
            'previous' => $context->get('previousUser'),
            'cookie' => $context->req->raw()->getCookieParams(),
            'tenant' => $scope->tenant,
            'touches' => $scope->touches,
            'validated' => $context->req->valid('query'),
            'greeting' => $this->prefix . $identity->name(),
        ]);
    }
}
