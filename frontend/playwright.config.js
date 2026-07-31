import { defineConfig, devices } from '@playwright/test'

/**
 * End-to-end tests run against the real docker-compose stack, not a
 * dev-server mock: `.env`'s VITE_KEYCLOAK_URL/VITE_API_URL are baked in at
 * build time as `http://localhost:*`, so the browser needs the actual
 * containers reachable under those names, exactly as a human developer
 * would use them. There is no `webServer` entry here on purpose - bring the
 * stack up yourself first (`docker-compose up -d`), the same precondition
 * `make test-integration` already has for the backend.
 */
export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false, // shared backend state (one seeded tipp year) - tests must not race each other
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: 'list',
  globalSetup: './tests/e2e/global-setup.js',

  use: {
    baseURL: 'http://localhost:3000',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure'
  },

  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] }
    }
  ]
})
