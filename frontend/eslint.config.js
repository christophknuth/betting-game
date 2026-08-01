import js from '@eslint/js'
import pluginVue from 'eslint-plugin-vue'
import globals from 'globals'

/**
 * ESLint for the SPA, in flat config.
 *
 * `vue/vue3-recommended` is the strictest of the plugin's presets - it stacks
 * essential (real bugs), strongly-recommended (readability) and recommended
 * (ordering and naming conventions) on top of each other. Together with
 * `js.configs.recommended` that is the configuration the Vue documentation
 * itself points at, so a newcomer needs to learn no house rules.
 *
 * Flat config since ESLint 9; `.eslintrc.cjs` is no longer read at all. The
 * environments that used to be `env: { browser }` / `env: { node }` are now
 * global sets keyed by file pattern - and because flat config *merges* rather
 * than replaces, each set is scoped to the files it belongs to instead of
 * being switched off again in an override.
 */
export default [
  { ignores: ['dist', 'node_modules'] },

  js.configs.recommended,
  ...pluginVue.configs['flat/recommended'],

  {
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'module'
    },
    rules: {
      // `App` is the one component that cannot sensibly have a second word, and
      // every other component here already has one.
      'vue/multi-word-component-names': ['error', { ignores: ['App'] }]
    }
  },

  {
    // The application itself runs in a browser. public/ is served verbatim and
    // runs there too - config.js is a plain script the container rewrites at
    // start-up, not part of the bundle.
    files: ['src/**/*.js', 'src/**/*.vue', 'public/**/*.js'],
    languageOptions: { globals: globals.browser }
  },

  {
    // Configs and the E2E suite run in Node - the specs drive a browser, but
    // the code doing the driving is Node code.
    files: ['vite.config.js', 'playwright.config.js', 'tests/e2e/**/*.js'],
    languageOptions: { globals: globals.node }
  },

  {
    // Unit tests run in jsdom and import their helpers from vitest explicitly,
    // so they need the browser globals rather than vitest's.
    files: ['tests/unit/**/*.js'],
    languageOptions: { globals: globals.browser }
  }
]
