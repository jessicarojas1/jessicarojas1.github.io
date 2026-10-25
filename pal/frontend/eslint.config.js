import pluginVue from 'eslint-plugin-vue'
import { defineConfigWithVueTs, vueTsConfigs } from '@vue/eslint-config-typescript'

export default defineConfigWithVueTs(
  // Config files are type-checked by tsconfig.node.json.
  { ignores: ['dist/**', 'node_modules/**', 'vite.config.ts', 'eslint.config.js'] },
  pluginVue.configs['flat/recommended'],
  vueTsConfigs.recommendedTypeChecked,
  {
    rules: {
      // Never render HTML from data: user content must go through text interpolation.
      'vue/no-v-html': 'error',
      'vue/multi-word-component-names': 'off',
      'no-restricted-syntax': [
        'error',
        { selector: "MemberExpression[property.name='innerHTML']", message: 'Do not assign innerHTML.' },
      ],
    },
  },
)
