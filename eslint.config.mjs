import js from '@eslint/js';
import tseslint from 'typescript-eslint';

/** @type {import("eslint").Linter.Config[]} */
export default tseslint.config(
  js.configs.recommended,
  ...tseslint.configs.recommended,
  {
    // Allow conventional _-prefixed unused variables everywhere
    rules: {
      '@typescript-eslint/no-unused-vars': ['error', { argsIgnorePattern: '^_', varsIgnorePattern: '^_' }],
    },
  },
  {
    // FSD boundary: shared/ and utils/ must never import from features/
    files: ['src/shared/**/*.{ts,tsx}', 'src/utils/**/*.{ts,tsx}'],
    rules: {
      'no-restricted-imports': [
        'error',
        {
          patterns: [
            {
              group: ['**/features/**'],
              message:
                'FSD violation: shared/ and utils/ layers must not import from features/. ' +
                'Use an adapter registered at app boot instead.',
            },
          ],
        },
      ],
    },
  },
  {
    ignores: ['app/Admin/assets/**', 'node_modules/**', 'vendor/**'],
  },
);
