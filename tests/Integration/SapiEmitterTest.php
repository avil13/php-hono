<?php

declare(strict_types=1);

namespace PhpHono\Tests\Integration;

use PhpHono\Adapter\SapiEmitter;
use PhpHono\Http\ResponseFactory;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

final class SapiEmitterTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testStalledBodyIsReportedAndClosedWithoutRetryingForever(): void
    {
        $body = $this->createMock(StreamInterface::class);
        $body->method('eof')->willReturn(false);
        $body->method('isSeekable')->willReturn(false);
        $body->expects(self::once())->method('read')->with(8192)->willReturn('');
        $body->expects(self::once())->method('close');
        $errors = [];
        $emitter = new SapiEmitter(static function (Throwable $error) use (&$errors): void {
            $errors[] = $error;
        });
        $emitter->emit(new ResponseFactory()->create($body));
        self::assertCount(1, $errors);
        self::assertInstanceOf(RuntimeException::class, $errors[0]);
        self::assertSame('Response stream made no progress during emission.', $errors[0]->getMessage());
    }
}
