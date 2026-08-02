import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import WinningsEntry from '@/components/WinningsEntry.vue'

/**
 * B-23: the winnings are entered as one sum for the ticket or class by class.
 *
 * What matters is the shape that leaves the component, because the API tells
 * the two apart by exactly that: a `totalAmount`, or a `winningClasses` list
 * that it adds up itself. Sending both would risk a contradiction the API
 * rejects, so "only one of them, ever" is the rule under test.
 */
describe('WinningsEntry', () => {
  function mountEntry() {
    return mount(WinningsEntry, { props: { drawId: 3 } })
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

  it('offers one amount per winning class', async () => {
    const entry = mountEntry()
    await chooseClasses(entry)

    expect(amountFields(entry)).toHaveLength(9)
  })

  it('sends only the classes that were filled in, and no total with them', async () => {
    const entry = mountEntry()
    await chooseClasses(entry)

    const fields = amountFields(entry)
    await fields[4].setValue('300')
    await fields[7].setValue('12,50')
    await entry.find('form').trigger('submit')

    // WINNING_CLASSES is keyed 1..9, so the fifth field is class 5
    expect(submitted(entry)).toEqual({
      winningClasses: [
        { winningClass: 5, amount: 300 },
        { winningClass: 8, amount: 12.5 }
      ]
    })
  })

  it('adds the classes up in cents, so three tenths are exactly 0,30', async () => {
    const entry = mountEntry()
    await chooseClasses(entry)

    const fields = amountFields(entry)
    await fields[0].setValue('0,10')
    await fields[1].setValue('0,20')

    expect(entry.text()).toContain('0,30')
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
