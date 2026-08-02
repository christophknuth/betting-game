const euro = new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' })
const decimal = new Intl.NumberFormat('de-DE', {
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
  useGrouping: false
})
const day = new Intl.DateTimeFormat('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' })
const moment = new Intl.DateTimeFormat('de-DE', {
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
  hour: '2-digit',
  minute: '2-digit'
})

/**
 * Money the API sent as a JSON number.
 *
 * Null is not zero here: `payout-share` answers `amount: null` while the
 * distribution is still outstanding, and showing 0,00 € would claim the year
 * paid out nothing.
 */
export function formatAmount(value) {
  return value === null || value === undefined ? '–' : euro.format(value)
}

/**
 * The same amount without the currency symbol, for inside an input field.
 *
 * `1,20` rather than `1,20 €`: the field carries its own € next to it, and a
 * symbol inside the value would be parsed back in on the next keystroke. No
 * thousands separator either - see parseAmount for why the input side does not
 * want to meet one.
 */
export function formatDecimal(value) {
  return value === null || value === undefined || Number.isNaN(value) ? '' : decimal.format(value)
}

/**
 * An amount as somebody types it, back into a number.
 *
 * German entry means the comma is the decimal separator, but the dot is what
 * years of forms have trained into people's fingers - so both are accepted.
 * Where both appear, the *last* one decides and the other is dropped as a
 * thousands separator: that is what pasting a formatted `1.234,56` looks like.
 *
 * A lone dot is therefore always a decimal point, and `1.234` reads as 1,23
 * after rounding rather than as one thousand. The alternative would be to guess
 * from the digit count, and guessing wrong on money is worse than a rule that
 * can be stated in one sentence.
 *
 * @returns {number|null} null when the field holds nothing that is a number
 */
export function parseAmount(input) {
  if (typeof input === 'number') {
    return Number.isFinite(input) ? input : null
  }

  const cleaned = String(input ?? '')
    // \s covers the non-breaking space Intl puts before the €, which is how a
    // copied "1,20 €" finds its way back into a field
    .replace(/[\s€]/g, '')

  if (cleaned === '') {
    return null
  }

  const lastComma = cleaned.lastIndexOf(',')
  const lastDot = cleaned.lastIndexOf('.')
  const separator = lastComma > lastDot ? ',' : '.'

  const normalised = cleaned
    .split('')
    .filter(character => character !== (separator === ',' ? '.' : ','))
    .join('')
    .replace(',', '.')

  // Number('') is 0 and Number('1e3') is 1000; neither is an amount somebody
  // typed, so the shape is checked before the conversion.
  if (!/^-?\d*\.?\d*$/.test(normalised) || !/\d/.test(normalised)) {
    return null
  }

  const value = Number(normalised)

  return Number.isFinite(value) ? value : null
}

export function formatDate(value) {
  return value ? day.format(new Date(value)) : '–'
}

export function formatDateTime(value) {
  return value ? moment.format(new Date(value)) : '–'
}

/** The six numbers, in the order the domain keeps them: ascending. */
export function formatNumbers(numbers) {
  return Array.isArray(numbers) ? numbers.join(' · ') : '–'
}

/*
 * The counterpart, parseNumbers, is gone. It read six numbers out of a text
 * field and rejected anything that was not six distinct numbers from 1 to 49 -
 * a rule that no longer has an input to guard: the numbers are picked off
 * NumberGrid, which cannot produce a seventh number, a 50 or a duplicate.
 */

/**
 * The tipp year lifecycle, in its intended order.
 *
 * The order is a reading aid, not a rule: B-18 allows every transition, because
 * a year closed too early has to be reopenable.
 */
export const TIPP_YEAR_STATUSES = ['planned', 'running', 'closed', 'distributed']

const STATUS_LABELS = {
  // Tipp year
  planned: 'geplant',
  running: 'laufend',
  closed: 'abgeschlossen',
  distributed: 'ausgeschüttet',
  // Draw
  scheduled: 'angesetzt',
  drawn: 'gezogen',
  evaluated: 'ausgewertet',
  // Ticket
  draft: 'Entwurf',
  submitted: 'eingereicht',
  settled: 'abgerechnet',
  // Fee and payout
  open: 'offen',
  paid: 'bezahlt',
  waived: 'erlassen',
  // Membership
  active: 'aktiv',
  ended: 'beendet'
}

export function statusLabel(status) {
  return STATUS_LABELS[status] ?? status ?? '–'
}

/** The lotto winning classes, as the API numbers them. */
export const WINNING_CLASSES = {
  1: '6 Richtige + SZ',
  2: '6 Richtige',
  3: '5 Richtige + SZ',
  4: '5 Richtige',
  5: '4 Richtige + SZ',
  6: '4 Richtige',
  7: '3 Richtige + SZ',
  8: '3 Richtige',
  9: '2 Richtige + SZ'
}

export function winningClassLabel(winningClass) {
  return WINNING_CLASSES[winningClass] ?? `Klasse ${winningClass}`
}
