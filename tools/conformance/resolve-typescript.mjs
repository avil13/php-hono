import { access } from 'node:fs/promises';

// Upstream uses extensionless TypeScript imports; Node supplies type stripping.
export async function resolve(specifier, context, nextResolve) {
  if (specifier.startsWith('.') && context.parentURL) {
    for (const suffix of ['.ts', '/index.ts']) {
      const candidate = new URL(specifier + suffix, context.parentURL);
      try {
        await access(candidate);
        return nextResolve(candidate.href, context);
      } catch (error) {
        if (error.code !== 'ENOENT' && error.code !== 'ENOTDIR') throw error;
      }
    }
  }
  return nextResolve(specifier, context);
}
