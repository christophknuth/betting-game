import { test, expect } from '@playwright/test'
import { loginAs, readFixture } from './fixtures'

/**
 * B-01/B-03/B-05 end to end: real login, real API, real MariaDB read models -
 * built by global-setup.js through the actual command handlers, the same way
 * QUICKSTART.md's walkthrough does it, just automated. testuser is
 * participant_id 2 (keycloak/realm-export.json).
 */
test.describe('participant read views (testuser, participant 2)', () => {
  test.beforeEach(async ({ page }) => {
    await loginAs(page, 'testuser', 'test123')
  })

  test('B-01: sees the assigned bet row with its six numbers', async ({ page }) => {
    await page.goto('/bet-row')

    const numbers = page.locator('.numbers .ball')

    await expect(numbers).toHaveCount(6)
    await expect(numbers).toHaveText(['7', '8', '9', '10', '11', '12'])
  })

  test('B-03: sees the fee the ticket submission created, still open', async ({ page }) => {
    const { feeId } = readFixture()
    test.skip(feeId === null, 'no fee was seeded for participant 2')

    await page.goto('/fees')

    const row = page.locator('tr', { hasText: `#${feeId}` })

    // total_cost 2.4 (2 rows x 1 draw x 1.20/row) split evenly over 2 rows
    await expect(row).toContainText('1,20')
    await expect(row.locator('.badge')).toHaveText('offen')
  })

  test('B-05: sees the recorded draw with its winning numbers', async ({ page }) => {
    const { tippYearId } = readFixture()

    await page.goto('/draws')

    // The field is a <select> once the participant's own memberships loaded
    // (which includes the one global-setup just created) and an <input>
    // otherwise - a shared dev stack may already have other tipp years, so
    // pick the seeded one explicitly rather than trust auto-selection.
    const field = page.locator('#tippYearId')
    const tagName = await field.evaluate(el => el.tagName)

    if (tagName === 'SELECT') {
      await field.selectOption(String(tippYearId))
    } else {
      await field.fill(String(tippYearId))
    }

    await page.getByRole('button', { name: 'Anzeigen' }).click()

    await expect(page.locator('.numbers .ball').first()).toBeVisible()
    await expect(page.getByText('Gewinn des Scheins')).toBeVisible()
  })
})
