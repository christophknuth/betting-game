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

    // No admin nav links should even be offered ...
    await expect(page.getByRole('link', { name: 'Tippjahre' })).toHaveCount(0)

    // ... and direct navigation is bounced home rather than shown.
    await page.goto('/admin/tipp-years')
    await expect(page).toHaveURL(/\/bet-row$/)
  })

  test('logout clears the session and returns to the login page', async ({ page }) => {
    await loginAs(page, 'testuser', 'test123')

    await page.getByRole('button', { name: 'Abmelden' }).click()

    await expect(page).toHaveURL(/\/login$/)
    await expect(page.getByRole('button', { name: 'Mit Keycloak anmelden' })).toBeVisible()
  })
})
