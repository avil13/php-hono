import { execFileSync } from 'node:child_process';
import { readFile, writeFile, mkdir } from 'node:fs/promises';
import { resolve, dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const upstreamVersion = '4.13.16';
const upstreamCommit = 'c91b60a251186fb024557c6d55a11d8e388f179c';
const upstream = resolve(process.argv[2] ?? '');
if (!process.argv[2]) throw new Error('Usage: generate.mjs /path/to/pinned/hono');
const actual = execFileSync('git', ['rev-parse', 'HEAD'], { cwd: upstream, encoding: 'utf8' }).trim();
if (actual !== upstreamCommit) throw new Error(`Expected ${upstreamCommit}, received ${actual}`);
execFileSync('git', ['diff', '--exit-code', 'HEAD', '--', 'src', 'package.json'], { cwd: upstream, stdio: 'pipe' });
const pkg = JSON.parse(await readFile(join(upstream, 'package.json'), 'utf8'));
if (pkg.version !== upstreamVersion) throw new Error('Unexpected upstream version');

const { Hono } = await import(pathToFileURL(join(upstream, 'src/hono.ts')).href);
const here = dirname(fileURLToPath(import.meta.url));
const scenarios = JSON.parse(await readFile(join(here, 'scenarios.json'), 'utf8'));
const fixtures = [];

for (const scenario of scenarios) {
  const app = new Hono();
  let trace = [];
  if (scenario.onError) app.onError((error, c) => c.text(`handled:${error.message}`, 500));
  for (const route of scenario.routes) {
    const handler = async (c, next) => {
      switch (route.kind) {
        case 'order':
          trace.push({ step: `${route.value}:before` });
          await next();
          trace.push({ step: `${route.value}:after` });
          return;
        case 'trace':
          trace.push({ step: `${route.value}:before`, params: c.req.param() });
          await next();
          trace.push({ step: `${route.value}:after`, params: c.req.param() });
          return;
        case 'params': return c.json(c.req.param());
        case 'text': return c.text(route.value);
        case 'method':
          c.header('X-Method', c.req.method);
          return c.text('page');
        case 'returnAfter':
          await next();
          return c.text(route.value);
        case 'replaceAfter':
          await next();
          c.res = c.text(route.value);
          return;
        case 'headerAfter':
          await next();
          c.header('X-After', route.value);
          return;
        case 'throw': throw new Error(route.value);
        case 'query': return c.json({
          first: c.req.query('x'), all: c.req.queries('x'),
          dot: c.req.query('a.b'), space: c.req.query('a b'), list: c.req.queries('list[]'),
        });
        case 'jsonBody': return c.json(await c.req.json());
        case 'twiceBody': return c.json([await c.req.text(), await c.req.text()]);
        case 'form': return c.json(await c.req.parseBody({ all: true }));
        case 'state': {
          const count = (c.get('count') ?? 0) + 1;
          c.set('count', count);
          return c.json({ count });
        }
        default: throw new Error(`Unknown handler kind ${route.kind}`);
      }
    };
    if (route.method === 'USE') app.use(route.path, handler);
    else app.on(route.method, route.path, handler);
  }
  const expected = [];
  for (const request of scenario.requests) {
    trace = [];
    const response = await app.request(request.path, {
      method: request.method, headers: request.headers, body: request.body,
    });
    const headers = Object.fromEntries([...response.headers].sort(([a], [b]) => a.localeCompare(b)));
    expected.push({ status: response.status, headers, body: await response.text(), trace });
  }
  const fixture = { ...scenario, classification: scenario.classification ?? 'compatible', expected };
  if (scenario.id === 'ordered-overlap') {
    // This target is the explicitly selected PHP contract, not upstream output.
    fixture.phpExpected = structuredClone(expected);
    fixture.phpExpected[0].trace[2].params = { id: 'me' };
  }
  fixtures.push(fixture);
}

const destination = resolve(here, '../../tests/Fixtures/hono-v4.13.16');
await mkdir(destination, { recursive: true });
await writeFile(join(destination, 'http.json'), JSON.stringify({ upstreamVersion, upstreamCommit, fixtures }, null, 2) + '\n');
console.log(`Generated ${fixtures.length} scenarios from Hono ${upstreamVersion} (${upstreamCommit}).`);
