import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import DrawFields from '@/components/DrawFields.vue'

/**
 * A draw is a day, six numbers and a Superzahl - entered the same way whether
 * it is being recorded (B-08) or put right (B-28).
 *
 * The two forms live on the same page, so the ids have to differ; a label
 * pointing at the field of another draw is the kind of fault nobody notices
 * until a screen reader reads it out.
 */
describe('DrawFields', () => {
  function mountFields(props = {}) {
    return mount(DrawFields, {
      props: {
        idPrefix: 'draw-7',
        drawDate: '2026-01-07',
        numbers: [3, 12, 19, 27, 33, 45],
        superzahl: 4,
        ...props
      }
    })
  }

  it('shows what is already entered', () => {
    const fields = mountFields()

    expect(fields.find('input[type="date"]').element.value).toBe('2026-01-07')
    expect(fields.findAll('.pick.picked').map(pick => pick.text()))
      .toEqual(['3', '12', '19', '27', '33', '45', '4'])
  })

  it('prefixes its ids so two draws can be edited on one page', () => {
    const fields = mountFields()

    expect(fields.find('input[type="date"]').attributes('id')).toBe('draw-7-date')
    expect(fields.find('label').attributes('for')).toBe('draw-7-date')
  })

  it('reports a changed date without writing into the draw itself', async () => {
    const fields = mountFields()

    await fields.find('input[type="date"]').setValue('2026-01-10')

    expect(fields.emitted('update:drawDate')?.at(-1)).toEqual(['2026-01-10'])
    // The parent owns the value: nothing is applied until it hands it back
    expect(fields.props('drawDate')).toBe('2026-01-07')
  })

  it('reports a corrected number and a corrected Superzahl', async () => {
    const fields = mountFields()

    // The 45 was wrong
    await fields.findAll('.pick').find(pick => pick.text() === '45').trigger('click')
    expect(fields.emitted('update:numbers')?.at(-1)[0]).toEqual([3, 12, 19, 27, 33])

    await fields.findAll('.superzahl.pick').find(pick => pick.text() === '7').trigger('click')
    expect(fields.emitted('update:superzahl')?.at(-1)).toEqual([7])
  })
})
