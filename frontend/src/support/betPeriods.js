/**
 * Generating the bet periods of a tipp year from a template (B-14).
 *
 * Twelve monthly periods used to mean twelve trips through the form with
 * hand-computed dates, and every slip produced a 409 the API had to catch.
 * The rules are simple enough to apply here instead: periods must lie inside
 * the tipp year and must not overlap each other.
 *
 * Kept free of Vue and of the API on purpose - tiling a date range is exactly
 * the kind of arithmetic that is worth checking directly rather than through a
 * mounted component.
 */

const MONTHS = [
  'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
  'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'
]

const MONTHS_SHORT = [
  'Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun',
  'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'
]

/**
 * The offered templates, as months per period.
 *
 * The number of periods is derived, not fixed: a tipp year is a freely defined
 * range (it need not be a calendar year), so "Quartale" over an 18-month year
 * is six periods, not four. Naming them "Q1..Q4" would have been a claim the
 * dates do not support.
 */
export const PERIOD_TEMPLATES = [
  { id: 'full', label: 'Eine Periode — das ganze Tippjahr', monthsPerPeriod: null },
  { id: 'half', label: 'Halbjahre', monthsPerPeriod: 6 },
  { id: 'quarter', label: 'Quartale', monthsPerPeriod: 3 },
  { id: 'month', label: 'Monate', monthsPerPeriod: 1 }
]

/** 'YYYY-MM-DD' -> { y, m, d }, with m zero-based. */
function parse(iso) {
  const [y, m, d] = iso.split('-').map(Number)

  return { y, m: m - 1, d }
}

function toIso({ y, m, d }) {
  return `${y}-${String(m + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`
}

function daysInMonth(y, m) {
  return new Date(Date.UTC(y, m + 1, 0)).getUTCDate()
}

/** Keeps the day of month, clamping it into shorter months (31 Jan + 1 = 28 Feb). */
function addMonths({ y, m, d }, count) {
  const total = y * 12 + m + count
  const year = Math.floor(total / 12)
  const month = total - year * 12

  return { y: year, m: month, d: Math.min(d, daysInMonth(year, month)) }
}

function addDays(date, count) {
  const shifted = new Date(Date.UTC(date.y, date.m, date.d + count))

  return { y: shifted.getUTCFullYear(), m: shifted.getUTCMonth(), d: shifted.getUTCDate() }
}

function compare(a, b) {
  return a.y - b.y || a.m - b.m || a.d - b.d
}

/**
 * Names a period after the range it actually covers.
 *
 * A single month gets its own name ("März 2027"); anything longer is spelled
 * out as a span. Deliberately descriptive rather than ordinal: a period
 * starting mid-March is not "Q1", and a label that says so would be a lie the
 * user only finds out about later.
 */
function nameFor(start, end) {
  if (start.y === end.y && start.m === end.m) {
    return `${MONTHS[start.m]} ${start.y}`
  }

  if (start.y === end.y) {
    return `${MONTHS_SHORT[start.m]}–${MONTHS_SHORT[end.m]} ${start.y}`
  }

  return `${MONTHS_SHORT[start.m]} ${start.y}–${MONTHS_SHORT[end.m]} ${end.y}`
}

/**
 * Splits a tipp year into consecutive, non-overlapping periods.
 *
 * `monthsPerPeriod` of `null` yields one period covering the whole range.
 * The result always tiles the range exactly: the first period starts on
 * `startDate`, the last ends on `endDate`, and each one begins the day after
 * its predecessor ends. That is what keeps the API from rejecting them.
 *
 * @param {string} startDate 'YYYY-MM-DD'
 * @param {string} endDate   'YYYY-MM-DD'
 * @param {number|null} monthsPerPeriod
 * @returns {{name: string, startDate: string, endDate: string, sequence: number}[]}
 */
export function generateBetPeriods(startDate, endDate, monthsPerPeriod) {
  if (!startDate || !endDate) {
    return []
  }

  const first = parse(startDate)
  const last = parse(endDate)

  if (compare(first, last) > 0) {
    return []
  }

  if (monthsPerPeriod === null) {
    return [{
      name: nameFor(first, last),
      startDate: toIso(first),
      endDate: toIso(last),
      sequence: 1
    }]
  }

  const periods = []
  let cursor = first
  let sequence = 1

  while (compare(cursor, last) <= 0) {
    // One day before the next period would start - so the periods touch
    // without overlapping, whatever the month lengths are.
    const nextStart = addMonths(first, sequence * monthsPerPeriod)
    const candidateEnd = addDays(nextStart, -1)
    const end = compare(candidateEnd, last) > 0 ? last : candidateEnd

    periods.push({
      name: nameFor(cursor, end),
      startDate: toIso(cursor),
      endDate: toIso(end),
      sequence
    })

    cursor = addDays(end, 1)
    sequence += 1
  }

  return periods
}

/**
 * Why a period cannot be added, or null when it can.
 *
 * The same three rules the API enforces (B-14), checked before sending so the
 * reason is readable and attached to the form rather than arriving as a 409.
 * This is convenience, not the safeguard - the unique key and the aggregate
 * still decide.
 */
export function rejectionReason(candidate, tippYear, existing = []) {
  if (!candidate.startDate || !candidate.endDate) {
    return null
  }

  const start = parse(candidate.startDate)
  const end = parse(candidate.endDate)

  if (compare(start, end) > 0) {
    return 'Das Ende liegt vor dem Beginn.'
  }

  if (tippYear) {
    const yearStart = parse(tippYear.startDate)
    const yearEnd = parse(tippYear.endDate)

    if (compare(start, yearStart) < 0 || compare(end, yearEnd) > 0) {
      return 'Die Periode muss innerhalb des Tippjahres liegen.'
    }
  }

  const clash = existing.find(period => {
    const otherStart = parse(period.startDate)
    const otherEnd = parse(period.endDate)

    return compare(start, otherEnd) <= 0 && compare(otherStart, end) <= 0
  })

  if (clash) {
    return `Überschneidet sich mit „${clash.name}“.`
  }

  return null
}

/**
 * The day after the last existing period, so the next one can be suggested.
 * Falls back to the tipp year's start while there are none.
 */
export function suggestedStart(tippYear, existing = []) {
  if (!tippYear) {
    return ''
  }

  if (!existing.length) {
    return tippYear.startDate
  }

  const latest = existing
    .map(period => parse(period.endDate))
    .reduce((max, date) => (compare(date, max) > 0 ? date : max))

  const next = addDays(latest, 1)

  return compare(next, parse(tippYear.endDate)) > 0 ? '' : toIso(next)
}
