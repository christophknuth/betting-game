/**
 * What a Laufzeit makes of a submission date.
 *
 * A Tippschein is handed in on one day, for a number of weeks, and for
 * Wednesday, Saturday or both. Its period and the number of draws follow from
 * that: 6 aus 49 is drawn on those two days and on holidays as well, so a week
 * of the order holds exactly one draw per chosen day.
 *
 * **This is a copy of a server-side rule, and it decides nothing.** The API
 * derives the same numbers from the same input (DrawSchedule) and those are
 * what is booked. Showing them here only means the cost is on screen before
 * the ticket is submitted rather than after - if the two ever disagree, the
 * server is right and this is the bug.
 */

const DAYS_PER_WEEK = 7

/** Draws per week, by choice of draw days - the keys the API expects. */
const DRAWS_PER_WEEK = {
  wednesday: 1,
  saturday: 1,
  both: 2
}

/** What the choice is called in the form and in the tables. */
export const DRAW_DAY_LABELS = {
  wednesday: 'nur Mittwoch',
  saturday: 'nur Samstag',
  both: 'Mittwoch und Samstag'
}

export const DRAW_DAY_OPTIONS = Object.entries(DRAW_DAY_LABELS).map(([value, label]) => ({
  value,
  label
}))

/** The same choice where a table cell has to hold it. */
const SHORT_DRAW_DAY_LABELS = {
  wednesday: 'Mi',
  saturday: 'Sa',
  both: 'Mi + Sa'
}

/**
 * How a submitted ticket's Laufzeit reads in a list.
 *
 * @returns {string|null} null for the tickets from before the Laufzeit was
 *   recorded - their draw count still says what they played
 */
export function scheduleLabel(durationWeeks, drawDays) {
  const short = SHORT_DRAW_DAY_LABELS[drawDays]

  if (!durationWeeks || !short) {
    return null
  }

  return `${durationWeeks} ${durationWeeks === 1 ? 'Woche' : 'Wochen'}, ${short}`
}

/**
 * The period and the draws a Spielauftrag covers.
 *
 * @param {string} periodStart 'YYYY-MM-DD', the day it is handed in
 * @param {number|string} durationWeeks
 * @param {string} drawDays 'wednesday' | 'saturday' | 'both'
 * @returns {{periodEnd: string, drawCount: number}|null} null while the form is incomplete
 */
export function drawSchedule(periodStart, durationWeeks, drawDays) {
  const weeks = Number(durationWeeks)
  const perWeek = DRAWS_PER_WEEK[drawDays]

  if (!periodStart || !Number.isInteger(weeks) || weeks < 1 || !perWeek) {
    return null
  }

  const start = new Date(`${periodStart}T00:00:00Z`)

  if (Number.isNaN(start.getTime())) {
    return null
  }

  // Both ends included: a one-week order handed in on a Monday runs through
  // the Sunday, not into the next Monday.
  const end = new Date(start.getTime() + (weeks * DAYS_PER_WEEK - 1) * 86400000)

  return {
    periodEnd: end.toISOString().slice(0, 10),
    drawCount: weeks * perWeek
  }
}
