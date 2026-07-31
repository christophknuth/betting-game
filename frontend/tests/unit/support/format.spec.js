import { describe, it, expect } from 'vitest'
import {
  formatAmount,
  formatDate,
  formatDateTime,
  formatNumbers,
  parseNumbers,
  statusLabel,
  winningClassLabel
} from '@/support/format'

const DASH = formatAmount(null)

describe('formatAmount', () => {
  it('renders null as a dash, not as zero (B-04: amount is null until the payout is booked)', () => {
    expect(formatAmount(null)).toBe(DASH)
    expect(formatAmount(undefined)).toBe(DASH)
  })

  it('renders an actual zero amount as money, not as the null-dash', () => {
    expect(formatAmount(0)).not.toBe(DASH)
    expect(formatAmount(0)).toContain('0,00')
  })

  it('formats a real amount in German locale currency', () => {
    const expected = new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(1234.5)

    expect(formatAmount(1234.5)).toBe(expected)
  })
})

describe('formatDate / formatDateTime', () => {
  it('renders a missing value as a dash', () => {
    expect(formatDate(null)).toBe(DASH)
    expect(formatDateTime(undefined)).toBe(DASH)
  })

  it('formats a date in German day.month.year order', () => {
    expect(formatDate('2026-01-15')).toBe('15.01.2026')
  })
})

describe('formatNumbers', () => {
  it('joins the six numbers in the order given, without re-sorting', () => {
    expect(formatNumbers([3, 1, 2])).toBe('3 · 1 · 2')
  })

  it('renders a dash for anything that is not an array', () => {
    expect(formatNumbers(null)).toBe(DASH)
    expect(formatNumbers(undefined)).toBe(DASH)
  })
})

/**
 * B-06: "genau sechs verschiedene Zahlen aus 1-49, aufsteigend gespeichert".
 * parseNumbers mirrors that rule on the client so a bad entry never earns a
 * round trip to the API just to learn it was invalid.
 */
describe('parseNumbers', () => {
  it('accepts six comma-separated numbers and sorts them ascending', () => {
    expect(parseNumbers('6,5,4,3,2,1')).toEqual({ numbers: [1, 2, 3, 4, 5, 6], error: null })
  })

  it('accepts space separators', () => {
    expect(parseNumbers('1 2 3 4 5 6')).toEqual({ numbers: [1, 2, 3, 4, 5, 6], error: null })
  })

  it('accepts semicolon separators', () => {
    expect(parseNumbers('1;2;3;4;5;6')).toEqual({ numbers: [1, 2, 3, 4, 5, 6], error: null })
  })

  it('accepts mixed separators', () => {
    expect(parseNumbers('1, 2;3 4,5;6')).toEqual({ numbers: [1, 2, 3, 4, 5, 6], error: null })
  })

  it('rejects fewer than six numbers', () => {
    expect(parseNumbers('1,2,3,4,5')).toEqual({ numbers: null, error: 'Genau sechs Zahlen angeben.' })
  })

  it('rejects more than six numbers', () => {
    expect(parseNumbers('1,2,3,4,5,6,7')).toEqual({ numbers: null, error: 'Genau sechs Zahlen angeben.' })
  })

  it('rejects a number outside 1-49', () => {
    expect(parseNumbers('0,2,3,4,5,6')).toEqual({ numbers: null, error: 'Nur ganze Zahlen von 1 bis 49.' })
    expect(parseNumbers('1,2,3,4,5,50')).toEqual({ numbers: null, error: 'Nur ganze Zahlen von 1 bis 49.' })
  })

  it('rejects a non-integer number', () => {
    expect(parseNumbers('1.5,2,3,4,5,6')).toEqual({ numbers: null, error: 'Nur ganze Zahlen von 1 bis 49.' })
  })

  it('rejects duplicate numbers', () => {
    expect(parseNumbers('1,1,2,3,4,5')).toEqual({
      numbers: null,
      error: 'Die sechs Zahlen müssen verschieden sein.'
    })
  })
})

describe('statusLabel', () => {
  it('translates a known status', () => {
    expect(statusLabel('running')).toBe('laufend')
  })

  it('passes an unknown status through unchanged rather than hiding it', () => {
    expect(statusLabel('something-new')).toBe('something-new')
  })

  it('renders a dash for a missing status', () => {
    expect(statusLabel(null)).toBe(DASH)
  })
})

describe('winningClassLabel', () => {
  it('translates a known winning class', () => {
    expect(winningClassLabel(1)).toBe('6 Richtige + SZ')
  })

  it('falls back to a generic label for an unknown class', () => {
    expect(winningClassLabel(99)).toBe('Klasse 99')
  })
})
