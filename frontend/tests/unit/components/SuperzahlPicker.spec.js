import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import SuperzahlPicker from '@/components/SuperzahlPicker.vue'

/**
 * The Superzahl sat beside the 7x7 grid as a number field, which made one slip
 * of the ticket two different kinds of entry. Ten balls is the same gesture -
 * and, unlike the six numbers, exactly one of them at a time.
 */
describe('SuperzahlPicker', () => {
  function mountPicker(modelValue = null) {
    const view = mount(SuperzahlPicker, {
      props: {
        modelValue,
        'onUpdate:modelValue': value => view.setProps({ modelValue: value })
      }
    })

    return view
  }

  const balls = view => view.findAll('.pick')

  it('offers nought to nine', () => {
    const view = mountPicker()

    expect(balls(view)).toHaveLength(10)
    expect(balls(view).map(ball => ball.text())).toEqual(
      ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9']
    )
  })

  it('picks one digit', async () => {
    const view = mountPicker()

    await balls(view)[7].trigger('click')

    expect(view.emitted('update:modelValue').at(-1)).toEqual([7])
  })

  it('replaces the choice rather than adding to it', async () => {
    const view = mountPicker(7)

    await balls(view)[3].trigger('click')

    expect(view.emitted('update:modelValue').at(-1)).toEqual([3])
    expect(balls(view).filter(ball => ball.classes().includes('picked'))).toHaveLength(1)
  })

  it('lets go of a digit hit by accident', async () => {
    const view = mountPicker(7)

    await balls(view)[7].trigger('click')

    expect(view.emitted('update:modelValue').at(-1)).toEqual([null])
  })

  it('says which one is chosen, and that it is a choice of one', () => {
    const view = mountPicker(0)

    // Zero is a Superzahl like any other - and a falsy one, which is exactly
    // where a truthiness check would drop it.
    expect(balls(view)[0].attributes('aria-checked')).toBe('true')
    expect(balls(view)[0].classes()).toContain('picked')
    expect(view.find('[role="radiogroup"]').exists()).toBe(true)
  })
})
