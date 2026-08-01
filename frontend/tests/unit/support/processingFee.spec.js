import { describe, it, expect } from 'vitest'
import { applicableProcessingFee } from '@/support/processingFee'

/**
 * The rate shown before a ticket is submitted.
 *
 * This mirrors a server rule (ProcessingFees::forPeriod) purely so the admin
 * sees the cost while filling the form. The boundary is the part worth pinning
 * down: it has to agree with the backend, and PHP's ProcessingFeesTest asserts
 * the same cases on the same dates. Drift between the two would show the wrong
 * price rather than fail loudly, which is why both sides are tested.
 */
describe('applicableProcessingFee', () => {
  const YEAR = { processingFeeSingleWeek: 0.6, processingFeeMultiWeek: 1.0 }

  it('bills a week, both ends included, at the single-week rate', () => {
    expect(applicableProcessingFee('2027-01-04', '2027-01-10', YEAR))
      .toEqual({ label: 'einwöchige', amount: 0.6 })
  })

  it('treats the eighth day as multi-week', () => {
    expect(applicableProcessingFee('2027-01-04', '2027-01-11', YEAR))
      .toEqual({ label: 'mehrwöchige', amount: 1.0 })
  })

  it('treats a single day as single-week', () => {
    expect(applicableProcessingFee('2027-01-04', '2027-01-04', YEAR).label).toBe('einwöchige')
  })

  it('bills the usual monthly ticket at the multi-week rate', () => {
    expect(applicableProcessingFee('2027-01-01', '2027-01-31', YEAR).label).toBe('mehrwöchige')
  })

  it('says nothing when no fee is charged at all', () => {
    // A row of zeroes is not worth a paragraph explaining it.
    const free = { processingFeeSingleWeek: 0, processingFeeMultiWeek: 0 }

    expect(applicableProcessingFee('2027-01-01', '2027-01-31', free)).toBeNull()
  })

  it('stays quiet while the form is incomplete or inverted', () => {
    expect(applicableProcessingFee('', '2027-01-31', YEAR)).toBeNull()
    expect(applicableProcessingFee('2027-01-31', '2027-01-01', YEAR)).toBeNull()
    expect(applicableProcessingFee('2027-01-01', '2027-01-31', null)).toBeNull()
  })

  it('copes with a tipp year that predates the rates', () => {
    expect(applicableProcessingFee('2027-01-01', '2027-01-31', {})).toBeNull()
  })
})
