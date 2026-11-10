<?php

declare(strict_types=1);

namespace PhpHono\Middleware;

use Closure;
use InvalidArgumentException;
use PhpHono\Context;
use PhpHono\Helper\Cookie;
use Psr\Http\Message\ResponseInterface;

final class Validator
{
    /** @var Closure(mixed, Context): mixed */
    private readonly Closure $validate;

    /** @var 'json'|'form'|'query'|'param'|'header'|'cookie' */
    private readonly string $target;

    /** @param callable(mixed, Context): mixed $validate */
    public function __construct(string $target, callable $validate)
    {
        if (!in_array($target, ['json', 'form', 'query', 'param', 'header', 'cookie'], strict: true)) {
            throw new InvalidArgumentException('Unsupported validation target: ' . $target);
        }

        $this->target = $target;
        $this->validate = Closure::fromCallable($validate);
    }

    public function __invoke(Context $context, callable $next): ?ResponseInterface
    {
        $mediaType = strtolower(trim(explode(';', $context->req->raw()->getHeaderLine('Content-Type'), limit: 2)[0]));
        $input = match ($this->target) {
            'json' => $mediaType === 'application/json'
                || str_starts_with($mediaType, 'application/') && str_ends_with($mediaType, '+json')
                    ? $context->req->json()
                    : [],
            'form' => $context->req->parseBody($mediaType !== 'multipart/form-data'),
            'query' => self::queryInput($context),
            'param' => $context->req->param(),
            'header' => $context->req->header(),
            'cookie' => Cookie::get($context),
        };
        $result = ($this->validate)($input, $context);

        if ($result instanceof ResponseInterface) {
            return $result;
        }

        $context->req->setValidated($this->target, $result);
        $next();

        return null;
    }

    /** @return array<array-key, string|non-empty-list<string>> */
    private static function queryInput(Context $context): array
    {
        $result = [];

        foreach ($context->req->queries() as $name => $values) {
            $result[$name] = count($values) === 1 ? $values[0] : $values;
        }

        return $result;
    }
}
