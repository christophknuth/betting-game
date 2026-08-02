import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import NumberGrid from '@/components/NumberGrid.vue'

/**
 * B-06: "genau sechs verschiedene Zahlen aus 1-49, aufsteigend gespeichert".
 *
 * The grid replaced the text field the two admin views used to parse, so it is
 * now the place where that rule is met on the client. Worth pinning down,
 * because the views dropped their own check in the same move: what the grid
 * emits is what goes into the request.
 */
describe('NumberGrid', () => {
  function mountGrid(picked = []) {
    return mount(NumberGrid, { props: { modelValue: picked } })
  }

  function balls(grid) {
    return grid.findAll('.number-grid .pick')
  }

  /** What the component last emitted through v-model. */
  function emitted(grid) {
    const updates = grid.emitted('update:modelValue')

    return updates ? updates.at(-1)[0] : null
  }

  it('offers all 49 numbers', () => {
    const grid = mountGrid()

    expect(balls(grid)).toHaveLength(49)
    expect(balls(grid).map(ball => ball.text())).toEqual(
      Array.from({ length: 49 }, (_, index) => String(index + 1))
    )
  })

  it('picks a number on click', async () => {
    const grid = mountGrid()

    await balls(grid)[11].trigger('click')

    expect(emitted(grid)).toEqual([12])
  })

  it('keeps the picked numbers ascending, whatever order they were clicked in', async () => {
    const grid = mountGrid([12, 33])

    await balls(grid)[2].trigger('click')

    expect(emitted(grid)).toEqual([3, 12, 33])
  })

  it('releases a picked number on a second click, so no number can be picked twice', async () => {
    const grid = mountGrid([3, 12])

    await balls(grid)[2].trigger('click')

    expect(emitted(grid)).toEqual([12])
  })

  it('locks the remaining numbers once six are picked', async () => {
    const grid = mountGrid([3, 12, 19, 27, 33, 45])

    // Picked ones stay clickable - that is the only way back out of a full grid.
    expect(balls(grid)[2].attributes('disabled')).toBeUndefined()
    expect(balls(grid)[0].attributes('disabled')).toBeDefined()

    await balls(grid)[0].trigger('click')

    expect(grid.emitted('update:modelValue')).toBeUndefined()
  })

  it('marks the picked numbers as pressed for screen readers', () => {
    const grid = mountGrid([3])

    expect(balls(grid)[2].attributes('aria-pressed')).toBe('true')
    expect(balls(grid)[3].attributes('aria-pressed')).toBe('false')
  })

  it('counts down how many numbers are still missing, then shows the six', async () => {
    const grid = mountGrid([3, 12, 19, 27])

    expect(grid.text()).toContain('Noch 2 Zahlen wählen.')

    await grid.setProps({ modelValue: [3, 12, 19, 27, 33] })
    expect(grid.text()).toContain('Noch eine Zahl wählen.')

    await grid.setProps({ modelValue: [3, 12, 19, 27, 33, 45] })
    expect(grid.text()).toContain('3 · 12 · 19 · 27 · 33 · 45')
  })
})
