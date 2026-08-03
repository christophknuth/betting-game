import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import WinningsEntry from '@/components/WinningsEntry.vue'

/**
 * B-23: the winnings are entered as one sum for the ticket or class by class.
 *
 * What matters is the shape that leaves the component, because the API tells
 * the two apart by exactly that: a `totalAmount`, or a `winningClasses` list of
 * what *one* row of each class was paid, which it multiplies out itself.
 * Sending both is a contradiction the API rejects, so "only one of them, ever"
 * is the rule under test - together with the running total, which has to be the
 * same multiplication the backend will do.
 */
describe('WinningsEntry', () => {
  // Two rows in class 5, one in class 8 - as the read model reports them once
  // the draw has been recorded.
  const ACHIEVED = [
    { winningClass: 5, rowCount: 2, amount: 0 },
    { winningClass: 8, rowCount: 1, amount: 0 }
  ]

  function mountEntry(winningClasses = ACHIEVED) {
    return mount(WinningsEntry, { props: { drawId: 3, winningClasses } })
  }

  const submitted = entry => entry.emitted('submit')?.at(-1)[0] ?? null

  async function chooseClasses(entry) {
    await entry.findAll('input[type="radio"]')[1].setValue()
  }

  /** The amount fields, which MoneyInput renders as text with a decimal keypad. */
  const amountFields = entry => entry.findAll('input[inputmode="decimal"]')

  it('sends the ticket total on its own, typed the German way', async () => {
    const entry = mountEntry()

    await amountFields(entry)[0].setValue('123,45')
    await entry.find('form').trigger('submit')

    expect(submitted(entry)).toEqual({ totalAmount: 123.45 })
  })

  it('offers only the classes rows of the ticket actually reached', async () => {
    const entry = mountEntry()
    await chooseClasses(entry)

    expect(amountFields(entry)).toHaveLength(2)
    expect(entry.text()).toContain('4 Richtige + SZ')
    expect(entry.text()).toContain('2 Reihen')
    expect(entry.text()).not.toContain('6 Richtige')
  })

  it('sends the amount per row, and no total with it', async () => {
    const entry = mountEntry()
    await chooseClasses(entry)

    await amountFields(entry)[0].setValue('150')
    await entry.find('form').trigger('submit')

    expect(submitted(entry)).toEqual({
      winningClasses: [{ winningClass: 5, amountPerRow: 150 }]
    })
  })

  it('multiplies by the rows of the class for the running total', async () => {
    const entry = mountEntry()
    await chooseClasses(entry)

    const fields = amountFields(entry)
    await fields[0].setValue('12,30')
    await fields[1].setValue('5,00')

    // 2 x 12,30 + 1 x 5,00
    expect(entry.text()).toContain('29,60')
  })

  it('multiplies in cents, so three rows at 0,07 are exactly 0,21', async () => {
    const entry = mountEntry([{ winningClass: 8, rowCount: 3, amount: 0 }])
    await chooseClasses(entry)

    await amountFields(entry)[0].setValue('0,07')

    expect(entry.text()).toContain('0,21')
  })

  it('leaves only the sum where no row won anything', async () => {
    const entry = mountEntry([])

    expect(entry.findAll('input[type="radio"]')[1].attributes('disabled')).toBeDefined()
    expect(entry.text()).toContain('Keine Reihe')
  })

  it('will not submit an empty entry', async () => {
    const entry = mountEntry()

    expect(entry.find('button').attributes('disabled')).toBeDefined()

    await chooseClasses(entry)
    expect(entry.find('button').attributes('disabled')).toBeDefined()

    await amountFields(entry)[0].setValue('5')
    expect(entry.find('button').attributes('disabled')).toBeUndefined()
  })

  it('reports a running command instead of accepting a second one', async () => {
    const entry = mountEntry()
    await entry.setProps({ pending: true })

    await amountFields(entry)[0].setValue('10')

    expect(entry.find('button').attributes('disabled')).toBeDefined()
    expect(entry.text()).toContain('Wird gesendet')
  })
})
