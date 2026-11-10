import { performance } from 'node:perf_hooks';

const [base, requestsArg = '1000'] = process.argv.slice(2);
if (!base) throw new Error('Usage: node benchmarks/http.mjs http://127.0.0.1:PORT [requests]');
const requests = Number(requestsArg);
if (!Number.isSafeInteger(requests) || requests < 10) throw new Error('requests must be >= 10');
const url = new URL('/route/99/42', base);
const results = [];
const one = async () => {
  const response = await fetch(url);
  const body = await response.text();
  if (response.status !== 200 || body !== '{"id":"42"}') throw new Error(`Response mismatch: ${response.status} ${body}`);
};
for (let i = 0; i < 20; ++i) await one();
for (const concurrency of [1, 8]) {
  const rounds = [];
  for (let round = 0; round < 3; ++round) {
    let next = 0;
    const started = performance.now();
    await Promise.all(Array.from({ length: concurrency }, async () => {
      while (next++ < requests) await one();
    }));
    rounds.push(requests / ((performance.now() - started) / 1000));
  }
  rounds.sort((a, b) => a - b);
  results.push({ concurrency, median_rps: rounds[1], min_rps: rounds[0], max_rps: rounds[2] });
}
console.log(JSON.stringify({ node: process.version, platform: process.platform, arch: process.arch, requests, rounds: 3, results }, null, 2));
