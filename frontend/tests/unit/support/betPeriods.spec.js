import { describe, it, expect } from 'vitest'
import {
  generateBetPeriods,
  rejectionReason,
  suggestedStart,
  PERIOD_TEMPLATES
} from '@/support/betPeriods'

/**
 * B-14: periods have to lie inside the tipp year and must not overlap each
 * other - the API answers 409 otherwise, enforced by the aggregate rather than
 * by a check in a handler.
 *
 * The generator exists so twelve monthly periods are one click instead of
 * twelve hand-computed forms, which makes its arithmetic the thing that has to
 * hold. The invariant worth pinning down is not any single date but the tiling:
 * the periods have to cover the year exactly, back to back, with no gap and no
 * overlap anywhere.
 */
describe('generateBetPeriods', () => {
  const YEAR = { startDate: '2027-01-01', endDate: '2027-12-31' }

  /** Every period touches the next, and the set spans exactly the range. */
  function expectTiling(periods, { startDate, endDate }) {
    expect(periods.length).toBeGreaterThan(0)
    expect(periods[0].startDate).toBe(startDate)
    expect(periods.at(-1).endDate).toBe(endDate)

    periods.forEach((period, index) => {
      expect(period.startDate <= period.endDate).toBe(true)
      expect(period.sequence).toBe(index + 1)

      if (index > 0) {
        const previousEnd = new Date(`${periods[index - 1].endDate}T00:00:00Z`)
        const expectedStart = new Date(previousEnd.getTime() + 86400000)

        expect(period.startDate).toBe(expectedStart.toISOString().slice(0, 10))
      }
    })
  }

  it('yields a single period covering the whole year for the "full" template', () => {
    const periods = generateBetPeriods(YEAR.startDate, YEAR.endDate, null)

    expect(periods).toHaveLength(1)
    expectTiling(periods, YEAR)
  })

  it('splits a calendar year into twelve months that tile it exactly', () => {
    const periods = generateBetPeriods(YEAR.startDate, YEAR.endDate, 1)

    expect(periods).toHaveLength(12)
    expectTiling(periods, YEAR)
    expect(periods[0]).toMatchObject({ name: 'Januar 2027', endDate: '2027-01-31' })
    expect(periods[1]).toMatchObject({ name: 'Februar 2027', endDate: '2027-02-28' })
    expect(periods.at(-1)).toMatchObject({ name: 'Dezember 2027', startDate: '2027-12-01' })
  })

  it('splits a calendar year into four quarters', () => {
    const periods = generateBetPeriods(YEAR.startDate, YEAR.endDate, 3)

    expect(periods).toHaveLength(4)
    expectTiling(periods, YEAR)
    expect(periods.map(p => p.startDate))
      .toEqual(['2027-01-01', '2027-04-01', '2027-07-01', '2027-10-01'])
  })

  it('splits a calendar year into two halves', () => {
    const periods = generateBetPeriods(YEAR.startDate, YEAR.endDate, 6)

    expect(periods).toHaveLength(2)
    expectTiling(periods, YEAR)
    expect(periods[0].endDate).toBe('2027-06-30')
  })

  it('gets February right in a leap year', () => {
    const leap = { startDate: '2028-01-01', endDate: '2028-12-31' }

    const periods = generateBetPeriods(leap.startDate, leap.endDate, 1)

    expectTiling(periods, leap)
    expect(periods[1]).toMatchObject({ name: 'Februar 2028', endDate: '2028-02-29' })
  })

  it('tiles a tipp year that is not a calendar year', () => {
    // The domain allows any range (USER_STORIES, "Festlegungen"), so the
    // generator may not assume January starts.
    const odd = { startDate: '2027-03-15', endDate: '2028-03-14' }

    const periods = generateBetPeriods(odd.startDate, odd.endDate, 1)

    expect(periods).toHaveLength(12)
    expectTiling(periods, odd)
  })

  it('derives the period count rather than forcing four quarters onto a longer year', () => {
    // 18 months of "quarters" is six periods. Forcing four and calling them
    // Q1..Q4 would name something the dates do not support.
    const long = { startDate: '2027-01-01', endDate: '2028-06-30' }

    const periods = generateBetPeriods(long.startDate, long.endDate, 3)

    expect(periods).toHaveLength(6)
    expectTiling(periods, long)
  })

  it('ends the last period on the tipp year, never past it', () => {
    // 10 months split into quarters leaves a short final period rather than
    // one that overruns the year - overrunning is what the API rejects.
    const short = { startDate: '2027-01-01', endDate: '2027-10-15' }

    const periods = generateBetPeriods(short.startDate, short.endDate, 3)

    expectTiling(periods, short)
    expect(periods.at(-1).endDate).toBe('2027-10-15')
  })

  it('returns nothing for an empty or inverted range', () => {
    expect(generateBetPeriods('', '2027-12-31', 1)).toEqual([])
    expect(generateBetPeriods('2027-12-31', '2027-01-01', 1)).toEqual([])
  })

  it('offers a template for every option the wizard shows', () => {
    expect(PERIOD_TEMPLATES.map(t => t.id)).toEqual(['full', 'half', 'quarter', 'month'])
  })
})

describe('rejectionReason', () => {
  const YEAR = { startDate: '2027-01-01', endDate: '2027-12-31' }
  const EXISTING = [{ name: 'Q1 2027', startDate: '2027-01-01', endDate: '2027-03-31' }]

  it('passes a period that fits into a free stretch of the year', () => {
    const candidate = { startDate: '2027-04-01', endDate: '2027-06-30' }

    expect(rejectionReason(candidate, YEAR, EXISTING)).toBeNull()
  })

  it('rejects a period reaching outside the tipp year', () => {
    const candidate = { startDate: '2027-12-01', endDate: '2028-01-31' }

    expect(rejectionReason(candidate, YEAR, EXISTING))
      .toBe('Die Periode muss innerhalb des Tippjahres liegen.')
  })

  it('rejects an inverted range before looking at anything else', () => {
    const candidate = { startDate: '2027-06-30', endDate: '2027-04-01' }

    expect(rejectionReason(candidate, YEAR, EXISTING)).toBe('Das Ende liegt vor dem Beginn.')
  })

  it('names the period a candidate overlaps', () => {
    const candidate = { startDate: '2027-03-31', endDate: '2027-06-30' }

    expect(rejectionReason(candidate, YEAR, EXISTING)).toContain('Q1 2027')
  })

  it('treats a shared boundary day as an overlap, the way the domain does', () => {
    // Two rows of the same participant valid on one day is exactly what the
    // no-overlap rule exists to prevent, so touching on a single day counts.
    const candidate = { startDate: '2027-01-15', endDate: '2027-02-15' }

    expect(rejectionReason(candidate, YEAR, EXISTING)).not.toBeNull()
  })

  it('stays quiet while the form is still incomplete', () => {
    expect(rejectionReason({ startDate: '', endDate: '' }, YEAR, EXISTING)).toBeNull()
  })
})

describe('suggestedStart', () => {
  const YEAR = { startDate: '2027-01-01', endDate: '2027-12-31' }

  it('suggests the start of the tipp year while there are no periods', () => {
    expect(suggestedStart(YEAR, [])).toBe('2027-01-01')
  })

  it('suggests the day after the latest period', () => {
    const existing = [
      { startDate: '2027-01-01', endDate: '2027-03-31' },
      { startDate: '2027-04-01', endDate: '2027-06-30' }
    ]

    expect(suggestedStart(YEAR, existing)).toBe('2027-07-01')
  })

  it('suggests nothing once the year is fully covered', () => {
    const existing = [{ startDate: '2027-01-01', endDate: '2027-12-31' }]

    expect(suggestedStart(YEAR, existing)).toBe('')
  })
})
