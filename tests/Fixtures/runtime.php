<?php

declare(strict_types=1);

use Middlewares\ResponseTime;
use Nyholm\Psr7\Response;
use PhpHono\Adapter\FrankenPhpWorker;
use PhpHono\Adapter\Sapi;
use PhpHono\Adapter\SapiEmitter;
use PhpHono\Context;
use PhpHono\Hono;
use PhpHono\Http\StreamingBody;
use PhpHono\Middleware\Psr15;
use PhpHono\Middleware\Validator;
use PhpHono\Tests\Fixtures\Identity;
use PhpHono\Tests\Fixtures\RuntimeProbe;
use PhpHono\Tests\Fixtures\RuntimeScope;
use PhpHono\Tests\Fixtures\User;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

require dirname(__DIR__, levels: 2) . '/vendor/autoload.php';

$boot = bin2hex(random_bytes(8));
$requests = 0;
$references = [];
$hono = new Hono();
$inspect = static function (Context $context, callable $next) use ($boot, &$requests, &$references): ResponseInterface {
    ++$requests;
    gc_collect_cycles();
    $references = array_values(array_filter(
        $references,
        static fn(WeakReference $reference): bool => $reference->get() !== null,
    ));
    $retained = count($references);
    $references[] = WeakReference::create($context);
    $context->set('previousUser', $context->get('user'));
    $context->set('user', $context->req->header('X-User'));
    $response = $next();
    if (!$response instanceof ResponseInterface) {
        throw new LogicException('Runtime fixture expected next() to return a ResponseInterface.');
    }
    $response = $response
        ->withHeader('X-Test-Boot', $boot)
        ->withHeader('X-Test-Request', (string) $requests)
        ->withHeader('X-Test-Retained', (string) $retained)
        ->withHeader('X-Test-Memory', (string) memory_get_usage());
    $context->setResponse($response);
    return $response;
};
$hono->use('*', $inspect);
$createScope = static fn(string $tenant): RuntimeScope => new RuntimeScope($tenant);
$hono->use('*', static function (Context $context, callable $next) use ($createScope, &$references): void {
    $scope = $createScope($context->req->header('X-Tenant') ?? 'default');
    $references[] = WeakReference::create($scope);
    $context->set(RuntimeScope::class, $scope);
    try {
        $next();
    } finally {
        $scope->closed = true;
    }
    $context->header('X-Test-Scope-Closed', 'yes');
    if ($context->req->raw()->getAttribute(Identity::class) !== null) {
        throw new LogicException('Bridge did not restore the upstream request.');
    }
});
$hono->use('*', Psr15::wrap(new ResponseTime()));
$hono->use('*', Psr15::wrap(new class implements MiddlewareInterface {
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Test input simulates an upstream identity provider; this fixture is not authentication.
        $user = $request->getHeaderLine('X-User');
        return $handler->handle(
            $user === ''
                ? $request->withoutAttribute(Identity::class)
                : $request->withAttribute(Identity::class, new User($user)),
        );
    }
}));
$hono->use('*', static function (Context $context, callable $next) use (&$references): ?ResponseInterface {
    $context->set(Identity::class, null);
    $identity = $context->req->raw()->getAttribute(Identity::class);
    if ($identity instanceof Identity) {
        $references[] = WeakReference::create($identity);
        $context->set(Identity::class, $identity);
    } elseif ($context->req->path() === '/probe') {
        return $context->text('Unauthorized', 401);
    }
    $next();
    return null;
});
$hono->get(
    '/probe',
    new Validator('query', static function (mixed $input, Context $context): mixed {
        if (!is_array($input) || !is_string($input['value'] ?? null)) {
            return $context->json(['error' => 'Missing value'], 422);
        }
        return ['value' => $input['value']];
    }),
    new RuntimeProbe('hello '),
);
$hono->get('/error', static fn() => throw new RuntimeException('Intentional request error'));
$hono->get('/deny', static fn(Context $context): ResponseInterface => $context->text('denied', status: 401));
$hono->post('/echo', static fn(Context $context): ResponseInterface => $context->text($context->req->text()));
$hono->post('/upload', static function (Context $context): ResponseInterface {
    $files = $context->req->raw()->getUploadedFiles();
    $file = $files['file'] ?? null;
    return $context->json([
        'parsed' => $context->req->raw()->getAttribute('php_hono.multipart_parsed'),
        'fields' => $context->req->raw()->getParsedBody(),
        'name' => $file instanceof \Psr\Http\Message\UploadedFileInterface ? $file->getClientFilename() : null,
        'content' => $file instanceof \Psr\Http\Message\UploadedFileInterface ? (string) $file->getStream() : null,
    ]);
});
$app = new class($hono) implements RequestHandlerInterface {
    public function __construct(
        private readonly RequestHandlerInterface $hono,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $directory = (string) getenv('PHP_HONO_TEST_DIRECTORY');
        if ($path === '/already-sent') {
            echo 'prefix';
            flush();
            return new Response(body: 'must not be emitted');
        }
        if ($path === '/disconnect') {
            return new Response(body: new StreamingBody(static function () use ($directory): Generator {
                $i = 0;
                try {
                    yield 'first';
                    for ($i = 0; $i < 1000; ++$i) {
                        usleep(1000);
                        yield str_repeat('x', times: 8192);
                    }
                } finally {
                    file_put_contents($directory . '/disconnected', (string) $i);
                }
            }));
        }
        if ($path === '/stream' || $path === '/failure') {
            return new Response(body: new StreamingBody(static function () use ($path, $directory): Generator {
                try {
                    yield 'first';
                    if ($path === '/failure') {
                        throw new RuntimeException('Secret producer error must not appear in HTTP output.');
                    }
                    $deadline = microtime(true) + 3;
                    while (!file_exists($directory . '/continue') && microtime(true) < $deadline) {
                        usleep(1000);
                    }
                    yield 'second';
                } finally {
                    file_put_contents($directory . ($path === '/failure' ? '/failed-closed' : '/closed'), data: 'yes');
                }
            }));
        }
        if ($path === '/head' || $path === '/no-body' || $path === '/reset-content') {
            return new Response(
                status: match ($path) {
                    '/no-body' => 204,
                    '/reset-content' => 205,
                    default => 200,
                },
                headers: ['Content-Length' => '123'],
                body: new StreamingBody(static function () use ($directory): Generator {
                    file_put_contents($directory . '/started', data: 'yes');
                    yield 'never';
                }),
            );
        }
        if ($path === '/cookies' || $path === '/') {
            return new Response(headers: ['Set-Cookie' => ['a=1; Path=/', 'b=2; Path=/']], body: 'ok');
        }
        return $this->hono->handle($request);
    }
};

if (getenv('PHP_HONO_TEST_WORKER') === '1') {
    $configuredMax = getenv('PHP_HONO_TEST_MAX_REQUESTS');
    $maxRequests = $configuredMax === false ? 20 : filter_var($configuredMax, FILTER_VALIDATE_INT);
    if (!is_int($maxRequests) || $maxRequests < 1) {
        throw new InvalidArgumentException('PHP_HONO_TEST_MAX_REQUESTS must be a positive integer.');
    }
    FrankenPhpWorker::run($app, maxRequests: $maxRequests, cleanup: static function (): void {
        file_put_contents((string) getenv('PHP_HONO_TEST_DIRECTORY') . '/cleanup', data: 'yes');
    });
} else {
    Sapi::run($app, new SapiEmitter(static function (Throwable $error): void {
        file_put_contents((string) getenv('PHP_HONO_TEST_DIRECTORY') . '/error', $error::class);
    }));
}
