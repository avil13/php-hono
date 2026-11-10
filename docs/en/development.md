# Development and checks

**English** | [Русский](../ru/development.md)

[Contents](README.md) · PHP 8.5+. Configuration and commands are part of the repository; the Git hook is enabled explicitly for each checkout.

## Tools

**Mago** handles formatting and linting; **PHPStan** checks types at level `max`; **PHPUnit** runs tests. The Mago analyzer is not a second mandatory type analyzer. PHPDoc generics, callable signatures, and PSR contracts remain a separate PHPStan check. See [PHPStan rule levels](https://phpstan.org/user-guide/rule-levels).

The project uses Mago **1.59.0**; the stable tag [`95a25d686a2b584c387326e466d6ead8c885465d`](https://github.com/carthage-software/mago/tree/95a25d686a2b584c387326e466d6ead8c885465d) was inspected. Its source supports the PHP 8.5 target; configuration and smoke scenarios are checked on PHP 8.5. These are development tools, not production runtime dependencies. See [supported PHP versions](https://github.com/carthage-software/mago/blob/95a25d686a2b584c387326e466d6ead8c885465d/crates/php-version/src/lib.rs).

Installing dependencies, running tests, and running the application require PHP 8.5+ and Composer. The local hook invokes `php tools/quality.php staged` directly: `php` must be available in the Git process's PATH, including GUI clients, but Composer is not required at commit time. Mago analyzes the target PHP 8.5 syntax independently of the quality wrapper's runtime. The local quality wrapper supports macOS/Linux on x86_64 and aarch64; CI uses Linux.

Prepare the environment:

```sh
composer install
composer tools:prepare
composer check
composer hooks:install
```

The version is pinned by an exact constraint and the Composer lockfile. The Composer wrapper may download the appropriate binary on first use; do this during environment/CI setup, before using pre-commit. The hook does not install dependencies or download anything: a missing prepared tool blocks the check with setup instructions. See [Mago installation](https://github.com/carthage-software/mago/blob/95a25d686a2b584c387326e466d6ead8c885465d/docs/content/en/guide/installation.md).

## Strict configuration

`mago.toml` is stored in the repository. The active profile is:

```toml
#:schema https://mago.carthage.software/1.59.0/schema.json
version = "1.59.0"
version-drift-fail-level = "patch"
php-version = "8.5"

[source]
paths = ["src", "tests", "examples", "benchmarks", "tools"]
includes = ["vendor"]
excludes = ["build", "coverage", "var"]

[source.glob]
literal-separator = true

[parser]
enable-short-tags = false

[linter]
minimum-fail-level = "warning"

[linter.rules]
strict-types = { enabled = true, level = "error" }
strict-behavior = { enabled = true, level = "error" }
no-eval = { enabled = true, level = "error" }
no-global = { enabled = true, level = "error" }
no-variable-variable = { enabled = true, level = "error" }
```

This combines the chosen version's default rules with explicit mandatory project rules. Errors and warnings fail the check. `strict-types` requires `declare(strict_types=1)` in PHP files; exceptions for intentionally invalid test fixtures must be narrowly scoped and explained. Do not create a blanket baseline that hides violations in new code. Suppressing a rule must be local and justified.

`version-drift-fail-level = "patch"` prevents silent tool-version changes; the `version` setting alone is insufficient. Updating Mago is a separate agreed change to the pin/lock/config, including review of new diagnostics. See [version checking](https://github.com/carthage-software/mago/blob/95a25d686a2b584c387326e466d6ead8c885465d/src/version_check.rs).

The `paths` list covers source code, tests, examples, benchmarks, and PHP tools. Check coverage when adding directories. `vendor` supplies definitions but is not formatted or linted as project code. Generated-file and fixture exclusions are explicit; do not rely only on `.gitignore`. See [source configuration](https://github.com/carthage-software/mago/blob/95a25d686a2b584c387326e466d6ead8c885465d/docs/content/en/guide/configuration.md).

Before finalizing a profile, inspect the actual files and rules:

```sh
vendor/bin/mago list-files --command formatter
vendor/bin/mago list-files --command linter
vendor/bin/mago lint --list-rules --json
vendor/bin/mago lint --explain strict-types
```

`--pedantic` is not enabled automatically: it activates every rule, including those unsuitable for the project, and ignores rules' PHP-version restrictions. A strict profile should be a validated set of requirements. See [lint configuration](https://github.com/carthage-software/mago/blob/95a25d686a2b584c387326e466d6ead8c885465d/docs/content/en/tools/linter/configuration-reference.md).

## Project commands

Composer scripts:

| Command | Action |
| --- | --- |
| `composer format` | Manual formatting through `mago fmt`; modifies working files |
| `composer format:check` | `mago fmt --check`, without writing |
| `composer lint` | `mago lint`, without `--fix` |
| `composer analyse` | PHPStan with the repository's `level: max` configuration |
| `composer test` | PHPUnit |
| `composer check:style` | Format check, then lint; a nonzero exit code from either step is preserved |
| `composer check` | Style, type analysis, and tests |
| `composer check:staged` | Style checks on a Git-index snapshot under the rules below |
| `composer hooks:install` | Locally enable the versioned `.githooks/pre-commit` |

CI installs from the lockfile on PHP 8.5, verifies the Mago version, and runs Composer validate/audit, `composer check`, and the relevant integration scenarios. Checks share the same repository configuration. Neither CI nor pre-commit performs autofixes.

## Pre-commit checks

The versioned `.githooks/pre-commit` invokes `php tools/quality.php staged`, the same entry point as `composer check:staged`. It checks **the content staged for commit**, including staged configuration, rather than merely reading working files with the same names. `tools/quality.php` exports raw Git blobs into a temporary index snapshot, runs `mago fmt --check` and `mago lint` against it using the prepared tool, and removes the snapshot afterward. The snapshot check covers the entire configured PHP source set, so rule changes do not overlook violations in otherwise unchanged files.

Workspace and config are passed explicitly: `mago --workspace "$staged_snapshot" --config "$staged_snapshot/mago.toml" fmt --check`, with the same prefix for `lint`. Here, `$staged_snapshot` is the temporary snapshot path and `mago` is the prepared binary of the pinned version. A missing staged `mago.toml` is an error; there is no fallback to user configuration. The hook removes external overrides such as `MAGO_NO_VERSION_CHECK` and `MAGO_PHP_VERSION` that could bypass the pin or PHP target. CI also uses the explicitly selected repository configuration.

The hook does not change the index/working tree or run `git add`, `stash`, or autofixes. It preserves partial staging and handles added, deleted, and renamed files; paths containing spaces are not split by the shell. Configuration is checked from the same snapshot. Definitions from installed `vendor` are allowed as dependencies, but must not replace staged project code. Snapshot preparation errors, missing tools, and pin mismatches fail the check. Unresolved conflicts, symlinks, and submodules in the staged tree are explicitly rejected: the snapshot supports regular files and their executable bits. Checkout filters are not run; the original Git-blob bytes are checked.

Locally installed `vendor` may not match a staged change to `composer.lock`: the hook checks staged PHP code against available dependency definitions, but does not verify the new dependency graph. Installing and running all checks against the committed lockfile in CI is mandatory; the hook does not reinstall dependencies. The Mago pin itself must match the staged configuration and lockfile, or the local check fails.

In Mago 1.59.0, `fmt --check --staged` are incompatible, and `fmt --staged` changes the index. With `lint --staged`, staged status selects paths, but their contents are then read from the working tree. These flags therefore do not replace the snapshot check for partial staging. See [formatter flags](https://github.com/carthage-software/mago/blob/95a25d686a2b584c387326e466d6ead8c885465d/src/commands/format.rs) and [lint file selection](https://github.com/carthage-software/mago/blob/95a25d686a2b584c387326e466d6ead8c885465d/src/commands/lint.rs).

Install the hook explicitly with `composer hooks:install`; `composer install` does not automatically overwrite user Git configuration. The installer checks the existing `core.hooksPath` and hooks. If it finds a conflict, it preserves them and prints integration instructions. Otherwise, it sets the local `.githooks` path and ensures the executable bit is set. Global Git settings are not changed.

Git allows local hooks to be bypassed, so CI must repeat checks against the commit itself. Full type/integration tests do not run inside pre-commit; they are available through `composer check` and run in CI.

## Toolchain regression checks

- On PHP 8.5, Mago accepts the configuration, valid code passes, and deliberately bad formatting or lint violations produce a nonzero exit code in the local command, hook, and CI.
- `strict_types`, PHP 8.5 syntax, and the PHPStan generic accessor are covered by minimal scenarios; typing of `Context::extension()` has additional T06 coverage.
- Partial staging is checked in both directions: a bad index / repaired working tree blocks the commit; a correct index / unfinished working tree is evaluated using the index.
- Changes to `mago.toml`, renames/deletions, and paths containing spaces are covered. After both success and failure, staged/unstaged contents remain byte-for-byte unchanged, and temporary files are removed.
- Existing hooks are preserved; a missing binary does not silently skip the check or trigger a network installation from the hook.

Tests in `tests/Tooling` create temporary Git repositories and check these scenarios without changing the current index or Git hooks. The main CI job runs `composer check`; a separate runtime matrix performs [FPM/FrankenPHP HTTP checks](deployment.md#reproducible-transport-checks). The third-party swew/test package is not part of the required toolchain; the project's testing API is PHPUnit.

Next: [testing](testing.md), [T01 plan (Russian)](../../spec/tasks/01-mvp.md#t01), and [check architecture (Russian)](../../spec/03-architecture.md).

## Checking the library archive

```sh
php tools/release/check-package.php
```

This command requires PHP 8.5, an up-to-date Composer 2, and `unzip`; set `COMPOSER_BINARY` to the Composer PHAR path if needed. The locally verified version is Composer 2.11.2. Older Composer 2.8.9 is incompatible with PHP 8.5 when loading a local ZIP; you can leave the system installation unchanged and supply a separate PHAR.

The check creates `build/php-hono-1.0.0.zip`, validates its contents, installs it with production dependencies into a separate temporary consumer, and runs routing/JSON/PSR-15 smoke checks. `vendor`, caches, build output, tests, tools, and development configuration are excluded through `.gitattributes` and the `composer.json` archive settings. Source code, documentation, examples, and the license remain in the package. Results are saved to `build/package-check.json`, and the temporary consumer is removed.

Version `1.0.0` is assigned only to the local package-repository entry used to test the unpublished ZIP; the command does not create a Git tag or publish the package. Run it again after final changes before a release. CI also checks the generator's memory bound with `php benchmarks/stream-memory.php`.

## Documentation languages

English is the primary documentation language. The English guides live in `docs/en/`; their Russian counterparts live in `docs/ru/`. The repository entry points are `README.md` and `README.ru.md`.

When changing a public API or its documented behavior, update both language versions and keep the links between corresponding pages intact. Use links to pages in the same language for navigation within a guide. The historical specification and planning documents under `spec/` remain in Russian.
