<?php

declare(strict_types=1);

namespace PhpHono\Middleware;

use Closure;
use InvalidArgumentException;
use PhpHono\Context;
use Psr\Http\Message\ResponseInterface;

final readonly class BearerAuth
{
    /** @var Closure(string, Context): bool */
    private Closure $verify;
    private string $challenge;

    /** @param callable(string, Context): bool $verify */
    public function __construct(callable $verify, string $realm = 'Restricted')
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $realm) === 1) {
            throw new InvalidArgumentException('Invalid authentication realm.');
        }
        $this->verify = Closure::fromCallable($verify);
        $this->challenge = 'Bearer realm="' . addcslashes($realm, characters: '\\"') . '"';
    }

    public static function token(#[\SensitiveParameter] string $token, string $realm = 'Restricted'): self
    {
        if (preg_match('~\A[A-Za-z0-9._+\x7e/-]+=*\z~D', $token) !== 1) {
            throw new InvalidArgumentException('Invalid bearer token format.');
        }

        return new self(static fn(string $provided): bool => hash_equals($token, $provided), $realm);
    }

    /** @param callable(): void $next */
    public function __invoke(Context $context, callable $next): ?ResponseInterface
    {
        $headers = $context->req->raw()->getHeader('Authorization');
        if (
            count($headers) === 1
            && preg_match('~\ABearer[ \t]+([A-Za-z0-9._+\x7e/-]+=*)\z~iD', $headers[0], $parts) === 1
        ) {
            if (($this->verify)($parts[1], $context)) {
                $next();
                return null;
            }
        }

        return $context->text('Unauthorized', 401, ['WWW-Authenticate' => $this->challenge]);
    }
}
