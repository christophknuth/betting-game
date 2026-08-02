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
