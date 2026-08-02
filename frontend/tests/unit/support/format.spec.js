import { describe, it, expect } from 'vitest'
import {
  formatAmount,
  formatDate,
  formatDateTime,
  formatDecimal,
  formatNumbers,
  parseAmount,
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

describe('formatDecimal', () => {
  it('writes the amount German, with two decimals and no currency symbol', () => {
    expect(formatDecimal(1.2)).toBe('1,20')
    expect(formatDecimal(0)).toBe('0,00')
  })

  it('renders nothing at all for a missing amount', () => {
    // An input field showing "–" would have that read back in as its value
    expect(formatDecimal(null)).toBe('')
    expect(formatDecimal(undefined)).toBe('')
  })

  it('leaves out the thousands separator the parser would have to guess at', () => {
    expect(formatDecimal(1234.5)).toBe('1234,50')
  })
})

/**
 * The way in for every amount the interface asks for. German entry means the
 * comma decides, but the dot is what years of forms have trained into people -
 * so both work, and pasting a formatted figure back in works too.
 */
describe('parseAmount', () => {
  it('reads the German comma', () => {
    expect(parseAmount('1,20')).toBe(1.2)
  })

  it('reads the dot as a decimal point as well', () => {
    expect(parseAmount('1.20')).toBe(1.2)
  })

  it('lets the last separator decide when both appear', () => {
    expect(parseAmount('1.234,56')).toBe(1234.56)
    expect(parseAmount('1,234.56')).toBe(1234.56)
  })

  it('ignores the spaces and the € of a pasted amount', () => {
    expect(parseAmount('1.234,56 €')).toBe(1234.56)
    expect(parseAmount(formatAmount(12.5))).toBe(12.5)
  })

  it('reads a lone dot as a decimal point, not as a thousands separator', () => {
    // Stated as a rule rather than guessed from the digit count: 1.234 is
    // 1,234 here, and rounding to cents is the field's job.
    expect(parseAmount('1.234')).toBe(1.234)
  })

  it('answers null for a field that holds no number', () => {
    expect(parseAmount('')).toBeNull()
    expect(parseAmount('   ')).toBeNull()
    expect(parseAmount('viel')).toBeNull()
    expect(parseAmount(null)).toBeNull()
    expect(parseAmount(',')).toBeNull()
  })

  it('does not let exponent notation through as an amount', () => {
    expect(parseAmount('1e3')).toBeNull()
  })

  it('passes a number through untouched', () => {
    expect(parseAmount(1.2)).toBe(1.2)
    expect(parseAmount(Number.NaN)).toBeNull()
  })

  it('keeps a negative sign, so the caller can refuse it knowingly', () => {
    expect(parseAmount('-5')).toBe(-5)
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

// B-06 used to be checked here as well, against parseNumbers. The rule has not
// moved, but the guard has: NumberGrid enforces it by construction, and
// components/NumberGrid.spec.js is where it is pinned down now.

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
