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
    expect(balls(grid)[2].attributes('aria-disabled')).toBe('false')
    expect(balls(grid)[0].attributes('aria-disabled')).toBe('true')

    await balls(grid)[0].trigger('click')

    expect(grid.emitted('update:modelValue')).toBeUndefined()
  })

  it('keeps a locked number reachable, so the keyboard can pass over it', () => {
    const grid = mountGrid([3, 12, 19, 27, 33, 45])

    // `disabled` would take it out of the tab order entirely, and with it the
    // way to the picked numbers beyond it.
    expect(balls(grid)[0].attributes('disabled')).toBeUndefined()
  })

  it('puts one number in the tab order, not forty-nine', () => {
    const grid = mountGrid()

    const tabbable = balls(grid).filter(ball => ball.attributes('tabindex') === '0')

    expect(tabbable).toHaveLength(1)
    expect(tabbable[0].text()).toBe('1')
  })

  it('moves the tab stop with the arrow keys', async () => {
    const grid = mountGrid()

    await grid.find('.number-grid').trigger('keydown', { key: 'ArrowRight' })
    expect(balls(grid)[1].attributes('tabindex')).toBe('0')

    // A row is seven numbers, so down from 2 is 9
    await grid.find('.number-grid').trigger('keydown', { key: 'ArrowDown' })
    expect(balls(grid)[8].attributes('tabindex')).toBe('0')

    await grid.find('.number-grid').trigger('keydown', { key: 'End' })
    expect(balls(grid)[48].attributes('tabindex')).toBe('0')
  })

  it('stops at the edge rather than wrapping round', async () => {
    const grid = mountGrid()

    // Left of 1 is off the board; wrapping to 49 would read as a reset
    await grid.find('.number-grid').trigger('keydown', { key: 'ArrowLeft' })
    expect(balls(grid)[0].attributes('tabindex')).toBe('0')

    await grid.find('.number-grid').trigger('keydown', { key: 'ArrowUp' })
    expect(balls(grid)[0].attributes('tabindex')).toBe('0')
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
