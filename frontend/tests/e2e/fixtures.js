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

/**
 * Navigates by clicking the nav link, the way a user does.
 *
 * `page.goto(path)` works too since the guard learned to await the Keycloak
 * bootstrap - `auth.spec.js` covers exactly that. Clicking is still what the
 * view specs use: it exercises the same path a user takes and skips a full
 * reload per test.
 */
export async function navigateTo(page, linkName, expectedPath) {
  // `exact` matters: Playwright matches an accessible name as a substring by
  // default, and the admin area's back link reads "Zur Teilnehmersicht" -
  // which contains the sidebar's "Teilnehmer" and made that click ambiguous.
  await page.getByRole('link', { name: linkName, exact: true }).click()
  await page.waitForURL(new RegExp(`${expectedPath}$`))
}

/**
 * Walks an admin from the participant area into the admin area.
 *
 * A login always lands on /bet-row, and the admin views live behind their own
 * layout - the single "Verwaltung" link is the only door, and /admin redirects
 * to the tipp years. Going through it rather than `page.goto` keeps the specs
 * honest about that door existing.
 */
export async function enterAdmin(page) {
  await navigateTo(page, 'Verwaltung', '/admin/tipp-years')
}
