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

    await page.getByRole('link', { name: 'Tippjahre' }).click()
    await expect(page).toHaveURL(/\/admin\/tipp-years$/)
  })

  test('a non-admin participant cannot reach an admin route (B-17)', async ({ page }) => {
    await loginAs(page, 'testuser', 'test123')

    // No admin nav links are offered - this part is meaningful on its own.
    await expect(page.getByRole('link', { name: 'Tippjahre' })).toHaveCount(0)

    // Typing the URL lands back on /bet-row and never renders the admin view.
    //
    // Weak on its own: a full load drops the requested deep link and settles
    // on /bet-row for *every* route (see navigateTo in fixtures.js), so this
    // would also pass with the guard removed. What actually pins the rule down
    // is tests/unit/router/guard.spec.js, which drives the guard client-side.
    // Kept because it still proves the admin view never reaches the screen.
    await page.goto('/admin/tipp-years')
    await expect(page).toHaveURL(/\/bet-row$/)
    await expect(page.getByRole('heading', { name: 'Tippjahre', level: 2 })).toHaveCount(0)
  })

  test('logout clears the session and returns to the login page', async ({ page }) => {
    await loginAs(page, 'testuser', 'test123')

    await page.getByRole('button', { name: 'Abmelden' }).click()

    await expect(page).toHaveURL(/\/login$/)
    await expect(page.getByRole('button', { name: 'Mit Keycloak anmelden' })).toBeVisible()
  })
})
