import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import { useNotificationStore } from '@/stores/notifications'

/**
 * The answers to writes now leave the page they were triggered from, so the
 * rules about how long they stay are the whole behaviour: a success takes
 * itself away, an error waits to be read, and a run of writes cannot bury the
 * interface under banners.
 */
describe('notification store', () => {
  let store

  beforeEach(() => {
    setActivePinia(createPinia())
    vi.useFakeTimers()
    store = useNotificationStore()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('keeps a success only as long as it takes to read', () => {
    store.success('Angenommen.')
    expect(store.items).toHaveLength(1)

    vi.advanceTimersByTime(4999)
    expect(store.items).toHaveLength(1)

    vi.advanceTimersByTime(1)
    expect(store.items).toEqual([])
  })

  it('leaves an error standing until it is dismissed', () => {
    const id = store.error('Laufen darf immer nur ein Tippjahr.')

    // An error that vanishes before it has been read is why people stop
    // trusting a banner - and this one carries the rule that said no.
    vi.advanceTimersByTime(60_000)
    expect(store.items).toHaveLength(1)

    store.dismiss(id)
    expect(store.items).toEqual([])
  })

  it('drops the oldest rather than covering the page', () => {
    const messages = ['eins', 'zwei', 'drei', 'vier', 'fünf']
    messages.forEach(message => store.error(message))

    expect(store.items.map(item => item.message)).toEqual(['zwei', 'drei', 'vier', 'fünf'])
  })

  it('does not take a later notification away with an expiring one', () => {
    store.success('erste')
    vi.advanceTimersByTime(3000)
    store.success('zweite')

    vi.advanceTimersByTime(2000)
    expect(store.items.map(item => item.message)).toEqual(['zweite'])

    vi.advanceTimersByTime(3000)
    expect(store.items).toEqual([])
  })

  it('tells the two kinds apart, because they are shown differently', () => {
    store.success('gut')
    store.error('schlecht')

    expect(store.items.map(item => item.type)).toEqual(['success', 'error'])
  })
})
