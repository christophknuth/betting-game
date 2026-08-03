import { test, expect } from '@playwright/test'
import { loginAs, enterAdmin, readFixture } from './fixtures'

/**
 * Setting up a tipp year through the wizard: B-10, B-14 and B-11 in the order
 * they have to happen.
 *
 * The point of the flow is that the period dates are computed rather than
 * typed. Four quarters used to be four forms with hand-worked-out boundaries,
 * and every slip came back as a 409 from the overlap rule - so the assertion
 * that matters is that the generated periods land in the read model exactly
 * tiling the year.
 *
 * The year is deliberately left `planned`: B-18 allows only one running tipp
 * year, and leaving this one running would break the next run's global setup.
 */
test.describe('admin sets up a tipp year (B-10, B-14, B-11)', () => {
  test('the wizard creates a year, its quarters and its members', async ({ page }) => {
    const { wizardYear } = readFixture()
    const name = `Assistent ${wizardYear}`

    await loginAs(page, 'admin', 'admin123')
    await enterAdmin(page)

    await page.getByRole('button', { name: 'Neues Tippjahr' }).click()

    // --- 1: Eckdaten -----------------------------------------------------
    await page.locator('#wz-name').fill(name)
    await page.locator('#wz-start').fill(`${wizardYear}-01-01`)
    await page.locator('#wz-end').fill(`${wizardYear}-12-31`)
    await page.locator('#wz-cost').fill('1.50')
    await page.getByRole('button', { name: 'Weiter' }).click()

    // --- 2: Perioden -----------------------------------------------------
    await expect(page.getByRole('heading', { name: `Tippperioden für ${name}` }))
      .toBeVisible()

    // The template drives both the preview and what gets written - picking
    // quarters has to yield four rows before anything is sent.
    await page.getByRole('radio', { name: /Quartale/ }).check()
    await expect(page.locator('.preview tbody tr')).toHaveCount(4)

    await page.getByRole('button', { name: '4 Perioden anlegen' }).click()

    // --- 3: Teilnehmer ---------------------------------------------------
    await expect(page.getByRole('heading', { name: 'Teilnehmer aufnehmen' })).toBeVisible()

    await page.getByRole('checkbox', { name: /Test User/ }).check()
    await page.getByRole('button', { name: '1 aufnehmen' }).click()

    // --- 4: Start --------------------------------------------------------
    await expect(page.getByRole('heading', { name: `${name} starten` })).toBeVisible()

    // The summary counts what the two previous steps actually wrote, in order:
    // Zeitraum, Perioden, Teilnehmer.
    const facts = page.locator('.facts dd')
    await expect(facts.nth(1)).toHaveText('4')
    await expect(facts.nth(2)).toHaveText('1')

    await page.getByRole('button', { name: 'Ohne Start beenden' }).click()

    // --- The read models, not the wizard's own optimism -------------------
    //
    // The wizard hands over to the finished year's own page, so the assertions
    // are made where an administrator would carry on working.
    await expect(page).toHaveURL(/\/admin\/tipp-years\/\d+$/)
    await expect(page.getByRole('heading', { name, level: 1 })).toBeVisible()
    await expect(page.locator('.status-select')).toHaveValue('planned')

    // Four quarters that tile the year exactly: this is what the generator is
    // for, and what the overlap rule would have rejected if it were wrong.
    const periodRows = page.locator('.card:has(h3:text-is("Tippperioden")) tbody tr')
    await expect(periodRows).toHaveCount(4)
    await expect(periodRows.first()).toContainText('01.01.')
    await expect(periodRows.last()).toContainText('31.12.')
  })

  test('the checklist reports what is still missing on an existing year', async ({ page }) => {
    const { tippYearId } = readFixture()

    await loginAs(page, 'admin', 'admin123')
    await enterAdmin(page)

    // The seeded year is `closed` and therefore still current - the default
    // filter is about what a year still owes, and its distribution is missing.
    await page.locator(`tr:has(td:text-is("#${tippYearId}"))`)
      .getByRole('link', { name: 'öffnen' })
      .click()

    await expect(page).toHaveURL(new RegExp(`/admin/tipp-years/${tippYearId}$`))

    // The seeded year is complete: a period, members, and it was closed again
    // at the end of the setup - so only the status step is still open.
    const checklist = page.locator('.checklist')
    await expect(checklist).toBeVisible()
    await expect(checklist.locator('.item.done')).toHaveCount(3)
    await expect(checklist).toContainText('Tippperioden')
  })
})
