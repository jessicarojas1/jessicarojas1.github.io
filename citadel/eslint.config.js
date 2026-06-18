'use strict';
/* CITADEL — ESLint flat config (correctness-focused, low-noise).
 * Catches the real-bug classes that `node --check` misses: undefined / mis-typed
 * identifiers, duplicate object keys, unreachable code, bad typeof, const
 * reassignment, etc. Style rules are intentionally OFF.
 *
 * Lives at citadel/ so it can lint BOTH the browser SPA (js/) and the Node
 * backend (server/, scripts/). eslint + its plugins are installed under
 * server/node_modules (where CI runs `npm ci`), so we resolve them by absolute
 * path. Run from citadel/:  server/node_modules/.bin/eslint js server scripts
 */
const path = require('path');
const NM = path.join(__dirname, 'server', 'node_modules');
const js = require(path.join(NM, '@eslint', 'js'));
const globals = require(path.join(NM, 'globals'));

module.exports = [
  { ignores: ['**/node_modules/**', '**/*.min.js', '**/results.json', '**/vendor/**', 'benchmark/owasp/BenchmarkJava/**'] },
  {
    files: ['**/*.js'],
    linterOptions: { reportUnusedDisableDirectives: 'off' },
    languageOptions: {
      ecmaVersion: 2022,
      sourceType: 'commonjs',           // parses both the IIFE/script SPA files and the CommonJS server files
      globals: {
        ...globals.node,
        ...globals.browser,
        ...globals.worker,
        // Project + CDN globals attached at runtime.
        CITADEL: 'writable',
        bootstrap: 'readonly',
        Chart: 'readonly',
        JSZip: 'readonly'
      }
    },
    rules: {
      ...js.configs.recommended.rules,
      // Keep correctness; relax the noisy/style members of "recommended".
      'no-unused-vars': ['warn', { args: 'none', varsIgnorePattern: '^_', caughtErrors: 'none', ignoreRestSiblings: true }],
      'no-empty': ['warn', { allowEmptyCatch: true }],
      'no-cond-assign': ['error', 'except-parens'],
      'no-constant-condition': ['error', { checkLoops: false }],
      'no-control-regex': 'off',
      'no-useless-escape': 'off',
      'no-prototype-builtins': 'off',
      'no-redeclare': 'error',
      'no-undef': 'error'
    }
  }
];
