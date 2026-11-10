# Documentation

[English](en/overview.md) | [Русский](ru/overview.md)

**English is the primary language.** Both editions cover the same public API and guides. Use the language links at the top of each page to switch to its translation.

| Language | Documentation | Project README |
| --- | --- | --- |
| English | [Guide index](en/overview.md) | [README](../README.md) |
| Русский | [Руководство](ru/overview.md) | [README на русском](../README.ru.md) |

Start with [Getting started](en/getting-started.md) or browse the [API reference](en/api.md).

## Documentation site

VitePress builds these guides into a site configured for [GitHub Pages](https://avil13.github.io/php-hono/). The homepage is in English, with English and Russian navigation and local search. This file remains the repository guide index; `docs/index.md` forwards visitors to the English homepage in `docs/en/index.md`; `docs/ru/index.md` is its Russian counterpart.

From the repository root, with Node.js 24 LTS:

```sh
npm ci
npm run docs:dev
```

Run `npm run docs:build` to create `docs/.vitepress/dist`, then `npm run docs:preview` to inspect that build. Publishing requires the initial Pages configuration described in [development](en/development.md#documentation-site-and-github-pages) / [разработке](ru/development.md#сайт-документации-и-github-pages).
