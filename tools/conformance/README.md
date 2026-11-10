# Pinned Hono conformance fixtures

The PHP suite reads committed fixtures offline. Node and an upstream checkout are needed only to regenerate them. The baseline is Hono **4.13.16**, commit `c91b60a251186fb024557c6d55a11d8e388f179c`.

With Node 24 or newer (native TypeScript stripping), run from the project root:

```sh
git clone --depth 1 --branch v4.13.16 https://github.com/honojs/hono.git /tmp/php-hono-upstream
node --experimental-loader ./tools/conformance/resolve-typescript.mjs ./tools/conformance/generate.mjs /tmp/php-hono-upstream
```

The generator verifies HEAD, package version and absence of tracked source changes before importing upstream. It executes the application scenarios in `scenarios.json`; `http.json` contains actual upstream status, headers, body and middleware traces. Regeneration has no timestamps and must produce the same bytes on repeated runs. Source scenarios are small original applications, with references to the corresponding upstream test areas. Hono is copyright its contributors and licensed under MIT; its source remains in the external checkout.

`manifest.json` states the selected profile, normalization and explicit PHP differences. `ordered-overlap.expected` is actual upstream output; its `phpExpected` field is the deliberately chosen PHP contract restoring caller params after `next()`. It is not presented as an upstream result. PHP-only behaviors such as typed Context extensions, resource limits and runtime adapters have their own tests. This fixture corpus is not a claim of full Hono parity.

The router regex subset accepts whole-segment parameters, literal characters, positive or negative character classes that cannot match `/`, `\d`, `\w`, `\s`, escaped punctuation, numeric quantifiers and non-capturing groups/alternation. Dot wildcard, anchors, capturing/lookaround/backreference constructs, Unicode-property escapes and any expression containing a literal path separator are rejected during registration. Use `[0-9]+`, `[A-Za-z_-]+` or `(?:book|film)`; matching is bounded with per-pattern PCRE limits, including failures rather than silent 404s. Global and terminal wildcards are routing constructs, not arbitrary regex.

On PHP, `ConformanceTest` checks matching classifications explicitly and compares JSON structurally without conflating JSON objects and arrays. Only Content-Type's optional semicolon whitespace and header-name/order differences are normalized. Body bytes and trace order are otherwise retained.
