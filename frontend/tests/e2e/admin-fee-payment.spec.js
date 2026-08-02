import { test, expect } from '@playwright/test'
import { loginAs, navigateTo, enterAdmin, readFixture } from './fixtures'

/**
 * B-07 through the real UI, not just a read: books a payment for the fee
 * global-setup.js created, and checks it actually round-trips through
 * Caddy -> PHP -> MariaDB -> the fee_read_model projection and back into the
 * list. The participant-facing read of the same fee (participant-views.spec)
 * only proves the seed worked; this proves a write does too.
 */
test.describe('admin books a fee payment (B-07)', () => {
  test('recording a payment updates the fee to paid', async ({ page }) => {
    // The admin's own fee (participant 1), so booking it does not disturb
    // participant-views.spec, which reads participant 2's and expects it open.
    const { tippYearId, adminFeeId: feeId } = readFixture()
    test.skip(feeId === null, 'no fee was seeded for participant 1')

    await loginAs(page, 'admin', 'admin123')

    // Inside the admin layout "Gebühren" is unambiguous: the participant's own
    // fees live in the other area, under its own navigation. Before the split
    // both sat in one bar and this had to match a gear suffix to tell them
    // apart.
    await enterAdmin(page)
    await navigateTo(page, 'Gebühren', '/admin/fees')

    // Both filters are selects over the admin lists now, not typed ids, and
    // choosing one loads - the button beside them says "Aktualisieren" and
    // means fetching the same filter again.
    await page.locator('#tippYearId').selectOption(String(tippYearId))
    await page.locator('#participantId').selectOption('1')

    // First cell only: the row also carries a ticket id in the same `#N`
    // shape, and matching the whole row's text picks the wrong fee as soon as
    // those ids coincide across runs.
    const row = page.locator(`tr:has(td:nth-child(1):text-is("#${feeId}"))`)
    await expect(row.locator('.badge')).toHaveText('offen')

    // `exact` on both: the row's link is "buchen" and the form's submit is
    // "Buchen", which differ only in case - and role matching is
    // case-insensitive, so without it either locator matches both.
    await row.getByRole('button', { name: 'buchen', exact: true }).click()

    // open() already defaults an open fee's status to "paid" - only the
    // payment date is worth setting explicitly here.
    await page.locator('#paidAt').fill('2026-01-20T10:00')
    await page.getByRole('button', { name: 'Buchen', exact: true }).click()

    await expect(page.getByText('Angenommen.')).toBeVisible()
    await expect(row.locator('.badge')).toHaveText('bezahlt')
  })
})
