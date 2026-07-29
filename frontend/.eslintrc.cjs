/**
 * ESLint for the SPA.
 *
 * `plugin:vue/vue3-recommended` is the strictest of the plugin's presets - it
 * stacks essential (real bugs), strongly-recommended (readability) and
 * recommended (ordering and naming conventions) on top of each other. Together
 * with `eslint:recommended` that is the configuration the Vue documentation
 * itself points at, so a newcomer needs to learn no house rules.
 *
 * `.cjs` rather than `.js` on purpose: package.json declares `"type": "module"`,
 * and ESLint 8 reads this file as CommonJS.
 */
module.exports = {
  root: true,

  env: {
    browser: true,
    es2022: true
  },

  extends: [
    'eslint:recommended',
    'plugin:vue/vue3-recommended'
  ],

  parserOptions: {
    ecmaVersion: 'latest',
    sourceType: 'module'
  },

  rules: {
    // `App` is the one component that cannot sensibly have a second word, and
    // every other component here already has one.
    'vue/multi-word-component-names': ['error', { ignores: ['App'] }]
  },

  overrides: [
    {
      // This file and nothing else: it runs in Node, not in the browser.
      files: ['.eslintrc.cjs'],
      env: { node: true, browser: false },
      parserOptions: { sourceType: 'script' }
    },
    {
      // Vite's config runs in Node too, but as an ES module.
      files: ['vite.config.js'],
      env: { node: true, browser: false }
    }
  ],

  ignorePatterns: ['dist', 'node_modules']
}
