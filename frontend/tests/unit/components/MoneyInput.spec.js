import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import MoneyInput from '@/components/MoneyInput.vue'

/**
 * Every amount in this interface is shown as `1,20 €`, so every amount has to
 * be enterable that way too. The field is the one place where that promise can
 * break, which is what these tests hold it to: the comma has to work, the dot
 * has to keep working, and what the field shows after blur has to be the figure
 * that will be sent.
 */
describe('MoneyInput', () => {
  /**
   * Mounted with the echo a real `v-model` provides. Without it the component
   * keeps reading the stale prop back, which is a broken parent rather than
   * anything this component can be asked about.
   */
  function mountField(modelValue = null) {
    const view = mount(MoneyInput, {
      props: {
        modelValue,
        'onUpdate:modelValue': value => view.setProps({ modelValue: value })
      }
    })

    return view
  }

  const field = view => view.find('input')

  /** Every value the field has handed up, in order - `null` included as itself. */
  const sentValues = view => (view.emitted('update:modelValue') ?? []).map(([value]) => value)

  const lastSent = view => sentValues(view).at(-1)

  it('takes the German comma', async () => {
    const view = mountField()

    await field(view).setValue('1,20')

    expect(lastSent(view)).toBe(1.2)
  })

  it('takes the dot as well, because that is what fingers are trained on', async () => {
    const view = mountField()

    await field(view).setValue('1.20')

    expect(lastSent(view)).toBe(1.2)
  })

  it('reads a pasted, fully formatted amount', async () => {
    const view = mountField()

    // What copying a figure out of the interface gives you, € and all
    await field(view).setValue('1.234,56 €')

    expect(lastSent(view)).toBe(1234.56)
  })

  it('shows an amount from outside in German', () => {
    const view = mountField(1.2)

    expect(field(view).element.value).toBe('1,20')
  })

  it('tidies what was typed on blur, rounded to cents', async () => {
    const view = mountField()

    await field(view).setValue('1.239')
    await field(view).trigger('blur')

    expect(field(view).element.value).toBe('1,24')
    expect(lastSent(view)).toBe(1.24)
  })

  it('does not rewrite the field mid-entry', async () => {
    const view = mountField()

    // Halfway through typing 1,20 - reformatting here would move the caret
    await field(view).setValue('1,')

    expect(field(view).element.value).toBe('1,')
    expect(lastSent(view)).toBe(1)
  })

  it('treats an empty field as no amount, not as zero', async () => {
    const view = mountField(5)

    await field(view).setValue('')

    expect(lastSent(view)).toBeNull()
    expect(view.find('.money').classes()).not.toContain('invalid')
  })

  it('marks something that is not an amount, and sends nothing', async () => {
    const view = mountField()

    await field(view).setValue('viel')

    // It started out empty and stays that way, so there may well be no event
    // at all - what matters is that no amount was ever handed up.
    expect(sentValues(view).filter(value => value !== null)).toEqual([])
    expect(view.find('.money').classes()).toContain('invalid')
    expect(field(view).attributes('aria-invalid')).toBe('true')
  })

  it('refuses a negative amount - no field here takes one', async () => {
    const view = mountField()

    await field(view).setValue('-5')

    expect(sentValues(view).filter(value => value !== null)).toEqual([])
    expect(view.find('.money').classes()).toContain('invalid')
  })

  it('carries the currency next to the entry, not inside it', async () => {
    const view = mountField(1.2)

    expect(view.find('.currency').text()).toBe('€')
    expect(field(view).element.value).not.toContain('€')
  })
})
