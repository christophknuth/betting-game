import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import DrawRows from '@/components/DrawRows.vue'

/**
 * B-24: every row of the ticket is shown for a draw, and the ones that won are
 * marked as such.
 *
 * The marking is the whole story here, so it is what the tests hold on to: a
 * row that reached a winning class, and inside every row which of the six
 * numbers were actually drawn. Both are read off colour in the interface,
 * which is exactly the kind of thing that breaks silently.
 */
describe('DrawRows', () => {
  const DRAWN = [3, 12, 19, 27, 40, 41]

  const winner = {
    ticketRowId: 1,
    participantId: 7,
    displayName: 'Anna',
    numbers: [3, 12, 19, 27, 33, 45],
    matchedNumbers: 4,
    superzahlMatched: true,
    winningClass: 5,
    amount: 123.45
  }

  const loser = {
    ticketRowId: 2,
    participantId: 8,
    displayName: 'Ben',
    numbers: [1, 2, 4, 5, 6, 7],
    matchedNumbers: 0,
    superzahlMatched: false,
    winningClass: null,
    amount: 0
  }

  function mountRows(rows, numbers = DRAWN) {
    return mount(DrawRows, { props: { rows, numbers } })
  }

  it('lists the losing rows too', () => {
    const view = mountRows([winner, loser])

    expect(view.findAll('.row')).toHaveLength(2)
    expect(view.text()).toContain('Ben')
  })

  it('highlights only the row that won, with its class and amount', () => {
    const view = mountRows([winner, loser])
    const rows = view.findAll('.row')

    expect(rows[0].classes()).toContain('winner')
    expect(rows[1].classes()).not.toContain('winner')
    expect(rows[0].text()).toContain('4 Richtige + SZ')
    expect(rows[0].text()).toContain('123,45')
  })

  it('says what a losing row achieved instead of leaving it blank', () => {
    const view = mountRows([{ ...loser, matchedNumbers: 3, superzahlMatched: true }])

    expect(view.text()).toContain('3 Richtige + Superzahl')
  })

  it('greys back the numbers that were not drawn', () => {
    const view = mountRows([winner])
    const balls = view.findAll('.row .ball')

    // 3, 12, 19, 27 were drawn; 33 and 45 were not
    expect(balls.map(ball => ball.classes().includes('miss'))).toEqual([
      false, false, false, false, true, true
    ])
  })

  it('marks nothing as a hit while the draw has no numbers', () => {
    const view = mountRows([winner], [])

    expect(view.findAll('.row .ball.miss')).toHaveLength(6)
  })

  it('says so when a row has not been evaluated yet', () => {
    const view = mountRows([{ ...loser, matchedNumbers: null, superzahlMatched: false }])

    expect(view.text()).toContain('noch nicht ausgewertet')
  })

  it('shows an empty state rather than a bare heading', () => {
    const view = mountRows([])

    expect(view.find('.state.empty').exists()).toBe(true)
  })
})
