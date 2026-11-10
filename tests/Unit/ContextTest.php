<?php

declare(strict_types=1);

namespace PhpHono\Tests\Unit;

use Closure;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PhpHono\Context;
use PhpHono\Hono;
use PhpHono\HonoRequest;
use PhpHono\Tests\Fixtures\Identity;
use PhpHono\Tests\Fixtures\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use stdClass;
use Throwable;

final class ContextTest extends TestCase
{
    public function testPreparedCookiesAppliedOnlyOnceAndHeaderRemovalPersists(): void
    {
        $c = $this->context();
        $c->header('X-Test', 'pending');
        $c->header('Set-Cookie', 'a=1');
        $response = $c->text('ok', 201, ['X-Test' => 'explicit', 'Set-Cookie' => ['b=2']]);
        self::assertNull($c->response());
        $c->setResponse($response);
        self::assertSame(['a=1', 'b=2'], $c->response()?->getHeader('Set-Cookie'));
        self::assertSame('explicit', $c->response()->getHeaderLine('X-Test'));
        $c->header('Set-Cookie', null);
        $c->setResponse($c->text('new'));
        self::assertFalse($c->response()?->hasHeader('Set-Cookie'));
    }

    public function testTypedExtensionIdentityAndReplacement(): void
    {
        $c = $this->context();
        $one = new stdClass();
        $two = new stdClass();
        $c->set(stdClass::class, $one);
        self::assertSame($one, $c->extension(stdClass::class));
        $c->set(stdClass::class, $two);
        self::assertSame($two, $c->extension(stdClass::class));
        $c->set(stdClass::class, null);
        self::assertTrue($c->has(stdClass::class));
        self::assertNull($c->get(stdClass::class));
        $this->expectException(LogicException::class);
        $c->extension(stdClass::class);
    }

    #[DataProvider('invalidExtensions')]
    public function testExtensionRequiresAnExplicitExactKeyAndMatchingObject(
        bool $present,
        string $key,
        mixed $value,
    ): void {
        $context = $this->context();
        if ($present) {
            $context->set($key, $value);
            self::assertTrue($context->has($key));
            self::assertSame($value, $context->get($key));
        }
        $this->expectException(LogicException::class);
        $context->extension(Identity::class);
    }

    /** @return iterable<string, array{bool, string, mixed}> */
    public static function invalidExtensions(): iterable
    {
        yield 'missing' => [false, Identity::class, null];
        yield 'explicit null' => [true, Identity::class, null];
        yield 'scalar identity' => [true, Identity::class, 'user'];
        yield 'wrong object' => [true, Identity::class, new stdClass()];
        yield 'case is exact' => [true, strtolower(Identity::class), new User('alice')];
        yield 'interface is not auto registered' => [true, User::class, new User('alice')];
    }

    public function testInterfaceExtensionReturnsTheSameReadonlyReferenceWithoutConstruction(): void
    {
        $constructions = 0;
        $state = new stdClass();
        $state->value = 'first';
        $identity = new class($state, static function () use (&$constructions): void {
            ++$constructions;
        }) implements Identity {
            /** @param Closure(): void $onConstruction */
            public function __construct(
                public readonly stdClass $state,
                Closure $onConstruction,
            ) {
                $onConstruction();
            }

            public function name(): string
            {
                return 'alice';
            }
        };
        $context = $this->context();
        $context->set(Identity::class, $identity);
        self::assertSame($identity, $context->extension(Identity::class));
        self::assertSame('alice', $context->extension(Identity::class)->name());
        $identity->state->value = 'changed';
        self::assertSame('changed', $state->value);
        self::assertSame($identity, $context->extension(Identity::class));
        self::assertSame(1, $constructions);
        $replacement = new User('bob');
        $context->set(Identity::class, $replacement);
        self::assertSame($replacement, $context->extension(Identity::class));
        self::assertSame('alice', $identity->name());
    }

    public function testExtensionErrorsUseOnErrorAndItsFailureFallsBackWithoutRecursion(): void
    {
        $handled = new Hono();
        $handled->get('/', static fn(Context $c): ResponseInterface => $c->text(
            $c->extension(Identity::class)->name(),
        ));
        $handled->onError(static function (Throwable $error, Context $c): ResponseInterface {
            self::assertInstanceOf(LogicException::class, $error);
            self::assertSame($error, $c->error);
            return $c->text('Missing identity', 422);
        });
        self::assertSame(422, $handled->request('/')->getStatusCode());

        $calls = 0;
        $handled->onError(static function (Throwable $error, Context $c) use (&$calls): ResponseInterface {
            ++$calls;
            return $c->json($c->extension(stdClass::class));
        });
        $fallback = $handled->request('/');
        self::assertSame(500, $fallback->getStatusCode());
        self::assertSame('Internal Server Error', (string) $fallback->getBody());
        self::assertSame(1, $calls);
    }

    private function context(): Context
    {
        return new Context(new HonoRequest(new Psr17Factory()->createServerRequest('GET', 'http://localhost/')));
    }
}
