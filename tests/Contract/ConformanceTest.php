<?php

declare(strict_types=1);

namespace PhpHono\Tests\Contract;

use Closure;
use LogicException;
use PhpHono\Context;
use PhpHono\Hono;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * @phpstan-type Trace list<array{step: string, params?: array<string, string>}>
 * @phpstan-type FixtureRoute array{method: string, path: string, kind: string, value?: string}
 * @phpstan-type FixtureRequest array{method: string, path: string, headers?: array<string, string>, body?: string}
 * @phpstan-type FixtureResponse array{status: int, headers: array<string, string>, body: string, trace: Trace}
 * @phpstan-type Fixture array{id: string, feature: string, sourceTest: string, classification: string, routes: list<FixtureRoute>, requests: list<FixtureRequest>, expected: list<FixtureResponse>, phpExpected?: list<FixtureResponse>, onError?: bool}
 * One fixture interpreter mirrors the upstream runner's deliberately small scenario vocabulary.
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class ConformanceTest extends TestCase
{
    public function testNonStrictMatchingPreservesTheOriginalRequestPath(): void
    {
        $app = new Hono(strict: false);
        $app->get('/about', static fn(Context $context): ResponseInterface => $context->text($context->req->path()));
        $response = $app->request('/about/');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('/about/', (string) $response->getBody());
    }

    /** @return iterable<string, array{Fixture}> */
    public static function fixtures(): iterable
    {
        $contents = file_get_contents(__DIR__ . '/../Fixtures/hono-v4.13.16/http.json');
        if ($contents === false) {
            throw new RuntimeException('Missing offline conformance fixtures.');
        }
        /** @var array{upstreamVersion: string, upstreamCommit: string, fixtures: list<Fixture>} $document */
        $document = json_decode($contents, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        if (
            $document['upstreamVersion'] !== '4.13.16'
            || $document['upstreamCommit'] !== 'c91b60a251186fb024557c6d55a11d8e388f179c'
        ) {
            throw new RuntimeException('Unexpected conformance baseline.');
        }
        foreach ($document['fixtures'] as $fixture) {
            yield $fixture['id'] => [$fixture];
        }
    }

    /** @param Fixture $fixture */
    #[DataProvider('fixtures')]
    public function testPinnedUpstreamProfile(array $fixture): void
    {
        $app = new Hono();
        /** @var Trace $trace */
        $trace = [];
        if ($fixture['onError'] ?? false) {
            $app->onError(static fn(Throwable $error, Context $context): ResponseInterface => $context->text(
                'handled:' . $error->getMessage(),
                500,
            ));
        }
        foreach ($fixture['routes'] as $route) {
            $handler = self::handler($route, $trace);
            if ($route['method'] === 'USE') {
                $app->use($route['path'], $handler);
            } else {
                $app->on($route['method'], $route['path'], $handler);
            }
        }

        if ($fixture['classification'] === 'intentional-difference') {
            if (!array_key_exists('phpExpected', $fixture)) {
                self::fail('A deliberate difference needs its own PHP expectation.');
            }
            $expectations = $fixture['phpExpected'];
        } else {
            self::assertSame('compatible', $fixture['classification']);
            $expectations = $fixture['expected'];
        }

        foreach ($fixture['requests'] as $index => $request) {
            $trace = [];
            $response = $app->request($request['path'], [
                'method' => $request['method'],
                'headers' => $request['headers'] ?? [],
                'body' => $request['body'] ?? '',
            ]);
            $expected = $expectations[$index];
            self::assertSame($expected['status'], $response->getStatusCode());
            $actualHeaders = [];
            foreach ($response->getHeaders() as $name => $values) {
                $actualHeaders[strtolower($name)] = implode(', ', $values);
            }
            self::assertSame(self::normalizeHeaders($expected['headers']), self::normalizeHeaders($actualHeaders));
            $body = (string) $response->getBody();
            if (str_starts_with($response->getHeaderLine('Content-Type'), 'application/json')) {
                self::assertEquals(
                    json_decode($expected['body'], associative: false, depth: 512, flags: JSON_THROW_ON_ERROR),
                    json_decode($body, associative: false, depth: 512, flags: JSON_THROW_ON_ERROR),
                );
            } else {
                self::assertSame($expected['body'], $body);
            }
            self::assertSame($expected['trace'], $trace);
        }
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private static function normalizeHeaders(array $headers): array
    {
        if (array_key_exists('content-type', $headers)) {
            $headers['content-type'] = str_replace('; ', replace: ';', subject: $headers['content-type']);
        }
        ksort($headers);

        return $headers;
    }

    /**
     * @param FixtureRoute $route
     * @param Trace $trace
     * @return Closure(Context, callable(): void): ?ResponseInterface
     * The switch intentionally matches the pinned JavaScript fixture vocabulary.
     * @mago-expect lint:halstead
     */
    private static function handler(array $route, array &$trace): Closure
    {
        return static function (Context $context, callable $next) use ($route, &$trace): ?ResponseInterface {
            $value = $route['value'] ?? '';
            switch ($route['kind']) {
                case 'order':
                    $trace[] = ['step' => $value . ':before'];
                    $next();
                    $trace[] = ['step' => $value . ':after'];
                    return null;
                case 'trace':
                    $trace[] = ['step' => $value . ':before', 'params' => $context->req->param()];
                    $next();
                    $trace[] = ['step' => $value . ':after', 'params' => $context->req->param()];
                    return null;
                case 'params':
                    return $context->json((object) $context->req->param());
                case 'text':
                    return $context->text($value);
                case 'method':
                    $context->header('X-Method', $context->req->method());
                    return $context->text('page');
                case 'returnAfter':
                    $next();
                    return $context->text($value);
                case 'replaceAfter':
                    $next();
                    $context->setResponse($context->text($value));
                    return null;
                case 'headerAfter':
                    $next();
                    $context->header('X-After', $value);
                    return null;
                case 'throw':
                    throw new RuntimeException($value);
                case 'query':
                    return $context->json([
                        'first' => $context->req->query('x'),
                        'all' => $context->req->queries('x'),
                        'dot' => $context->req->query('a.b'),
                        'space' => $context->req->query('a b'),
                        'list' => $context->req->queries('list[]'),
                    ]);
                case 'jsonBody':
                    return $context->json($context->req->json());
                case 'twiceBody':
                    return $context->json([$context->req->text(), $context->req->text()]);
                case 'form':
                    return $context->json($context->req->parseBody(true));
                case 'state':
                    $previous = $context->get('count');
                    $count = is_int($previous) ? $previous + 1 : 1;
                    $context->set('count', $count);
                    return $context->json(['count' => $count]);
                default:
                    throw new LogicException('Unknown fixture handler: ' . $route['kind']);
            }
        };
    }
}
