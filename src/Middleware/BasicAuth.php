<?php

declare(strict_types=1);

namespace PhpHono\Middleware;

use Closure;
use InvalidArgumentException;
use PhpHono\Context;
use Psr\Http\Message\ResponseInterface;

final readonly class BasicAuth
{
    /** @var Closure(string, string, Context): bool */
    private Closure $verify;
    private string $challenge;

    /** @param callable(string, string, Context): bool $verify */
    public function __construct(callable $verify, string $realm = 'Restricted')
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $realm) === 1) {
            throw new InvalidArgumentException('Invalid authentication realm.');
        }
        $this->verify = Closure::fromCallable($verify);
        $this->challenge = 'Basic realm="' . addcslashes($realm, characters: '\\"') . '", charset="UTF-8"';
    }

    public static function credentials(
        string $username,
        #[\SensitiveParameter]
        string $password,
        string $realm = 'Restricted',
    ): self {
        return new self(static function (string $providedUser, #[\SensitiveParameter] string $providedPassword) use (
            $username,
            $password,
        ): bool {
            $userMatches = hash_equals($username, $providedUser);
            $passwordMatches = hash_equals($password, $providedPassword);

            return $userMatches && $passwordMatches;
        }, $realm);
    }

    /** @param callable(): void $next */
    public function __invoke(Context $context, callable $next): ?ResponseInterface
    {
        $headers = $context->req->raw()->getHeader('Authorization');
        if (
            count($headers) === 1
            && preg_match('/\ABasic[ \t]+([A-Za-z0-9+\/]+={0,2})\z/iD', $headers[0], $parts) === 1
        ) {
            $decoded = base64_decode($parts[1], strict: true);
            if ($decoded !== false && str_contains($decoded, ':') && preg_match('/[\x00-\x1f\x7f]/', $decoded) !== 1) {
                [$username, $password] = explode(':', $decoded, limit: 2);
                if (($this->verify)($username, $password, $context)) {
                    $next();
                    return null;
                }
            }
        }

        return $context->text('Unauthorized', 401, ['WWW-Authenticate' => $this->challenge]);
    }
}
