import { test, expect } from '@playwright/test'
import { loginAs } from './fixtures'

/**
 * B-15/B-16/B-17 through the real stack: SSO via Keycloak, the admin area
 * gated by role, and a clean logout. No seeded business data needed - these
 * are pure auth/navigation rules.
 */
test.describe('authentication and role gating', () => {
  test('an admin can log in via Keycloak and reach the admin area', async ({ page }) => {
    await loginAs(page, 'admin', 'admin123')

    await expect(page).toHaveURL(/\/bet-row$/)

    // The admin area sits behind its own layout, and this link is the only way
    // in. /admin itself redirects onto the tipp years.
    await page.getByRole('link', { name: 'Verwaltung' }).click()
    await expect(page).toHaveURL(/\/admin\/tipp-years$/)

    // Arrived in the other area, not merely at another URL: the participant
    // navigation is gone and the admin sidebar has taken its place.
    await expect(page.getByRole('link', { name: 'Meine Reihe' })).toHaveCount(0)
    await expect(page.getByRole('link', { name: 'Betrieb' })).toBeVisible()
  })

  test('a non-admin participant cannot reach an admin route (B-17)', async ({ page }) => {
    await loginAs(page, 'testuser', 'test123')

    // The door is not even shown - this part is meaningful on its own.
    await expect(page.getByRole('link', { name: 'Verwaltung' })).toHaveCount(0)

    // Typing the URL lands back on /bet-row and never renders the admin view.
    // Meaningful on its own now that deep links survive a reload: a
    // participant genuinely gets turned away here, rather than every route
    // collapsing onto /bet-row regardless of role.
    await page.goto('/admin/tipp-years')
    await expect(page).toHaveURL(/\/bet-row$/)
    await expect(page.getByRole('heading', { name: 'Tippjahre', level: 2 })).toHaveCount(0)
  })

  test('a deep link survives a full page load (no bounce to /bet-row)', async ({ page }) => {
    await loginAs(page, 'testuser', 'test123')

    // A real reload of a protected route: the SPA restarts and Keycloak has
    // to restore the session before the guard may judge it. This used to land
    // on /bet-row, which made every bookmark to a subpage useless.
    await page.goto('/fees')

    await expect(page).toHaveURL(/\/fees$/)
    await expect(page.getByRole('heading', { name: 'Meine Gebühren' })).toBeVisible()
  })

  test('logout clears the session and returns to the login page', async ({ page }) => {
    await loginAs(page, 'testuser', 'test123')

    await page.getByRole('button', { name: 'Abmelden' }).click()

    await expect(page).toHaveURL(/\/login$/)
    await expect(page.getByRole('button', { name: 'Anmelden' })).toBeVisible()
  })

  test('an anonymous visitor is handed to Keycloak without a page in between', async ({ page }) => {
    // Deliberately a protected route, not /login: the SPA used to answer this
    // with its own screen carrying a single button. Now the login form itself
    // is what comes up, and it comes back to the route that was asked for.
    await page.goto('/fees')

    await expect(page.locator('#kc-login')).toBeVisible()

    await page.locator('#username').fill('testuser')
    await page.locator('#password').fill('test123')
    await page.locator('#kc-login').click()

    await expect(page).toHaveURL(/\/fees$/)
  })
})
