/**
 * Which Bearbeitungsentgelt a Spielauftrag falls under.
 *
 * The lottery company charges once per ticket on top of the rows, at a rate
 * that depends on how long the order runs. The tipp year carries both rates;
 * this picks between them so the admin sees what a ticket will cost before
 * submitting it rather than after.
 *
 * **This is a copy of a server-side rule, and it decides nothing.** The API
 * reads the rate from the tipp year itself (ProcessingFees::forPeriod) and that
 * is what is charged. Showing it here only saves a round trip - if the two ever
 * disagree, the server is right and this is the bug.
 */

/** A Spielauftrag counts as single-week while it covers at most seven days. */
const SINGLE_WEEK_DAYS = 7

/**
 * @param {string} periodStart 'YYYY-MM-DD'
 * @param {string} periodEnd   'YYYY-MM-DD'
 * @param {{processingFeeSingleWeek?: number, processingFeeMultiWeek?: number}} tippYear
 * @returns {{label: string, amount: number}|null} null while there is nothing to show
 */
export function applicableProcessingFee(periodStart, periodEnd, tippYear) {
  if (!periodStart || !periodEnd || !tippYear) {
    return null
  }

  const single = Number(tippYear.processingFeeSingleWeek ?? 0)
  const multi = Number(tippYear.processingFeeMultiWeek ?? 0)

  // Nothing is charged, so there is nothing worth a paragraph of explanation.
  if (single === 0 && multi === 0) {
    return null
  }

  const start = new Date(`${periodStart}T00:00:00Z`)
  const end = new Date(`${periodEnd}T00:00:00Z`)

  if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime()) || end < start) {
    return null
  }

  // Both ends included: Monday to Sunday is one week, not six days.
  const days = Math.round((end - start) / 86400000) + 1

  return days <= SINGLE_WEEK_DAYS
    ? { label: 'einwöchige', amount: single }
    : { label: 'mehrwöchige', amount: multi }
}
