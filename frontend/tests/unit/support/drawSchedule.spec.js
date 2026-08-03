import { describe, it, expect } from 'vitest'
import { drawSchedule, scheduleLabel } from '@/support/drawSchedule'

/**
 * The period and the draws shown before a ticket is submitted.
 *
 * This mirrors a server rule (DrawSchedule) so the admin sees what a
 * Spielauftrag will cover while filling the form. PHP's DrawScheduleTest
 * asserts the same cases on the same dates: drift between the two would show a
 * wrong period rather than fail loudly, which is why both sides are tested.
 */
describe('drawSchedule', () => {
  it('ends a one-week order on the seventh day, the day of submission included', () => {
    expect(drawSchedule('2027-01-04', 1, 'both'))
      .toEqual({ periodEnd: '2027-01-10', drawCount: 2 })
  })

  it('counts two draws a week on both days and one on a single day', () => {
    expect(drawSchedule('2027-01-04', 4, 'both').drawCount).toBe(8)
    expect(drawSchedule('2027-01-04', 4, 'wednesday').drawCount).toBe(4)
    expect(drawSchedule('2027-01-04', 4, 'saturday').drawCount).toBe(4)
  })

  it('leaves the period alone when only the draw days change', () => {
    expect(drawSchedule('2027-01-04', 4, 'saturday').periodEnd).toBe('2027-01-31')
    expect(drawSchedule('2027-01-04', 4, 'both').periodEnd).toBe('2027-01-31')
  })

  it('carries over the end of a month and of a year', () => {
    expect(drawSchedule('2027-12-20', 3, 'both').periodEnd).toBe('2028-01-09')
  })

  it('stays quiet while the form is incomplete or nonsensical', () => {
    expect(drawSchedule('', 4, 'both')).toBeNull()
    expect(drawSchedule('2027-01-04', '', 'both')).toBeNull()
    expect(drawSchedule('2027-01-04', 0, 'both')).toBeNull()
    expect(drawSchedule('2027-01-04', 2.5, 'both')).toBeNull()
    expect(drawSchedule('2027-01-04', 4, 'friday')).toBeNull()
  })

  it('reads a duration typed into a text field', () => {
    // v-model on a number input still hands over a string
    expect(drawSchedule('2027-01-04', '2', 'both'))
      .toEqual({ periodEnd: '2027-01-17', drawCount: 4 })
  })
})

describe('scheduleLabel', () => {
  it('names the Laufzeit and the draw days', () => {
    expect(scheduleLabel(4, 'both')).toBe('4 Wochen, Mi + Sa')
    expect(scheduleLabel(1, 'wednesday')).toBe('1 Woche, Mi')
    expect(scheduleLabel(2, 'saturday')).toBe('2 Wochen, Sa')
  })

  it('says nothing about a ticket that predates the Laufzeit', () => {
    expect(scheduleLabel(null, null)).toBeNull()
    expect(scheduleLabel(4, null)).toBeNull()
  })
})
