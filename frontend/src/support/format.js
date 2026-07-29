const euro = new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' })
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

/**
 * Parses the six numbers out of a text field.
 *
 * Deliberately permissive about separators and deliberately strict about the
 * result: comma, space or semicolon all work, but anything that is not six
 * distinct numbers from 1 to 49 is rejected here rather than sent off to earn a
 * 400. The domain enforces the same rule in `LottoNumbers`; this only spares
 * the round trip.
 */
export function parseNumbers(input) {
  const parts = String(input)
    .split(/[\s,;]+/)
    .filter(part => part !== '')

  if (parts.length !== 6) {
    return { numbers: null, error: 'Genau sechs Zahlen angeben.' }
  }

  const numbers = parts.map(Number)

  if (numbers.some(n => !Number.isInteger(n) || n < 1 || n > 49)) {
    return { numbers: null, error: 'Nur ganze Zahlen von 1 bis 49.' }
  }

  if (new Set(numbers).size !== 6) {
    return { numbers: null, error: 'Die sechs Zahlen müssen verschieden sein.' }
  }

  return { numbers: [...numbers].sort((a, b) => a - b), error: null }
}

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
