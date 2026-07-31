import { test, expect } from '@playwright/test'
import { loginAs, navigateTo } from './fixtures'

/**
 * B-21: creating a participant through the UI.
 *
 * Until this endpoint existed the only way in was an INSERT by hand, and such
 * a row stood in no event - the next projection rebuild dropped it. So the
 * assertion that matters is not just "a row appeared" but that the command
 * went through the normal write path and the list reflects it afterwards.
 */
test.describe('admin creates a participant (B-21)', () => {
  test('a new participant is created and appears in the list', async ({ page }) => {
    const name = `E2E Person ${Date.now()}`

    await loginAs(page, 'admin', 'admin123')
    await navigateTo(page, 'Teilnehmer', '/admin/participants')

    await page.locator('#displayName').fill(name)
    await page.getByRole('button', { name: 'Anlegen', exact: true }).click()

    await expect(page.getByText('Angenommen.')).toBeVisible()

    const row = page.locator('tr', { hasText: name })
    await expect(row).toHaveCount(1)
    await expect(row.locator('.badge')).toHaveText('aktiv')

    // The form clears itself, so the next entry does not silently repeat the
    // previous name.
    await expect(page.locator('#displayName')).toHaveValue('')
  })

  test('a created participant is selectable where a participant is needed', async ({ page }) => {
    const name = `E2E Pick ${Date.now()}`

    await loginAs(page, 'admin', 'admin123')
    await navigateTo(page, 'Teilnehmer', '/admin/participants')

    await page.locator('#displayName').fill(name)
    await page.getByRole('button', { name: 'Anlegen', exact: true }).click()
    await expect(page.getByText('Angenommen.')).toBeVisible()

    // The point of the list endpoint: the bet-row view used to ask for a raw
    // participant id, which meant knowing it by heart.
    await navigateTo(page, 'Reihen', '/admin/bet-rows')

    await expect(page.locator('#participantId')).toContainText(name)
  })
})
