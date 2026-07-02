// Next.js 16 removed the `next lint` CLI; ESLint now runs directly via `eslint .`
// (see package.json). `eslint-config-next` 16.x ships its rulesets as native
// flat-config arrays (no FlatCompat/legacy bridge needed) — spread them in.
import nextCoreWebVitals from 'eslint-config-next/core-web-vitals';
import nextTypescript from 'eslint-config-next/typescript';

const eslintConfig = [
  ...nextCoreWebVitals,
  ...nextTypescript,
  { ignores: ['.next/**', 'node_modules/**'] }
];

export default eslintConfig;
