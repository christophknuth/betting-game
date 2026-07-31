import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const FIXTURE_PATH = path.join(path.dirname(fileURLToPath(import.meta.url)), '.fixture.json')

/** The IDs global-setup.js seeded (tipp year, bet period, ticket, draw, fee). */
export function readFixture() {
  return JSON.parse(readFileSync(FIXTURE_PATH, 'utf-8'))
}

/**
 * Logs in through the real Keycloak redirect - not a mocked token. The SPA's
 * "Mit Keycloak anmelden" button redirects to Keycloak's own login page,
 * which is what this fills in and submits.
 */
export async function loginAs(page, username, password) {
  await page.goto('/login')
  await page.getByRole('button', { name: 'Mit Keycloak anmelden' }).click()
  await page.locator('#username').fill(username)
  await page.locator('#password').fill(password)
  await page.locator('#kc-login').click()
  await page.waitForURL(/^http:\/\/localhost:3000\/(?!login)/)
}
