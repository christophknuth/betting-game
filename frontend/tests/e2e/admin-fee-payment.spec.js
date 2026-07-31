import { test, expect } from '@playwright/test'
import { loginAs, readFixture } from './fixtures'

/**
 * B-07 through the real UI, not just a read: books a payment for the fee
 * global-setup.js created, and checks it actually round-trips through
 * Caddy -> PHP -> MariaDB -> the fee_read_model projection and back into the
 * list. The participant-facing read of the same fee (participant-views.spec)
 * only proves the seed worked; this proves a write does too.
 */
test.describe('admin books a fee payment (B-07)', () => {
  test('recording a payment updates the fee to paid', async ({ page }) => {
    const { tippYearId, feeId } = readFixture()
    test.skip(feeId === null, 'no fee was seeded for participant 2')

    await loginAs(page, 'admin', 'admin123')
    await page.goto('/admin/fees')

    await page.locator('#tippYearId').fill(String(tippYearId))
    await page.locator('#participantId').fill('2')
    await page.getByRole('button', { name: 'Filtern' }).click()

    const row = page.locator('tr', { hasText: `#${feeId}` })
    await expect(row.locator('.badge')).toHaveText('offen')

    await row.getByRole('button', { name: 'buchen' }).click()

    // open() already defaults an open fee's status to "paid" - only the
    // payment date is worth setting explicitly here.
    await page.locator('#paidAt').fill('2026-01-20T10:00')
    await page.getByRole('button', { name: 'Buchen' }).click()

    await expect(page.getByText('Angenommen.')).toBeVisible()
    await expect(row.locator('.badge')).toHaveText('bezahlt')
  })
})
