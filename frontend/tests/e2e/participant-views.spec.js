import { test, expect } from '@playwright/test'
import { loginAs, navigateTo, readFixture } from './fixtures'

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

  /**
   * The view shows the row of the period running *today*, and nothing else:
   * no participant-facing endpoint lists bet periods, so there is nothing to
   * pick from and the field that used to ask for a period id was a lookup
   * nobody could perform. The seed takes the first free calendar year (tipp
   * years may not overlap), so which of the two tests below runs depends on
   * whether that landed in this year - exactly one of them always does.
   */
  const seededYearIsRunning = () => readFixture().calendarYear === new Date().getFullYear()

  test('B-01: sees the assigned bet row with its six numbers', async ({ page }) => {
    test.skip(!seededYearIsRunning(), 'the seeded tipp year is not the running one')

    // Login lands on /bet-row already
    const numbers = page.locator('.numbers .ball')

    await expect(numbers).toHaveCount(6)
    await expect(numbers).toHaveText(['7', '8', '9', '10', '11', '12'])
  })

  test('B-01: says so when no period is running today', async ({ page }) => {
    test.skip(seededYearIsRunning(), 'the seeded tipp year is the running one')

    // A 404 is an answer here, not a fault - and it has to read as one.
    await expect(page.locator('.state.empty')).toBeVisible()
    await expect(page.locator('.numbers .ball')).toHaveCount(0)
  })

  test('B-03: sees the fee the ticket submission created, still open', async ({ page }) => {
    // participantFeeId, not the admin's: admin-fee-payment.spec books the
    // admin's own fee, and sharing one row would make these two specs depend
    // on the order they happen to run in.
    const { participantFeeId: feeId, tippYearId } = readFixture()
    test.skip(feeId === null, 'no fee was seeded for participant 2')

    await navigateTo(page, 'Gebühren', '/fees')

    // Narrow to the seeded year: the stack keeps the fees of every previous
    // run, and this spec is only making a claim about its own. The years are
    // a select over the caller's own memberships now, and choosing one is the
    // request - there is no "Filtern" button left to press.
    await page.locator('#tippYearId').selectOption(String(tippYearId))

    // The fee and ticket id columns are gone - a participant can act on
    // neither. Narrowed to the seeded year there is exactly one ticket, so the
    // one row in the table is the one this spec is about.
    const row = page.locator('table.data tbody tr')
    await expect(row).toHaveCount(1)

    // total_cost 2.4 (2 rows x 1 draw x 1.20/row) split evenly over 2 rows
    await expect(row).toContainText('1,20')
    await expect(row.locator('.badge')).toHaveText('offen')
  })

  test('B-05: sees the recorded draw with its winning numbers', async ({ page }) => {
    const { tippYearId } = readFixture()

    await navigateTo(page, 'Ziehungen', '/draws')

    // The select fills once the participant's own memberships have loaded, so
    // the option is waited for rather than selected blind - the seed makes
    // testuser a member, so it is certain to arrive.
    const field = page.locator('select#tippYearId')
    await expect(field.locator(`option[value="${tippYearId}"]`)).toBeAttached()
    await field.selectOption(String(tippYearId))

    // Choosing the year is the request; no button follows it any more.
    await expect(page.locator('.numbers .ball').first()).toBeVisible()
    await expect(page.getByText('Gewinn des Scheins')).toBeVisible()
  })
})
