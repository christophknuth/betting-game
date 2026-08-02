import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

import CommandFeedback from '@/components/CommandFeedback.vue'
import { useNotificationStore } from '@/stores/notifications'

/**
 * The component renders nothing and reports upwards instead, so what it does
 * is only visible in the store. Both directions matter: a command that comes
 * back must be announced, and one that has not answered yet must not be.
 */
describe('CommandFeedback', () => {
  let store

  beforeEach(() => {
    setActivePinia(createPinia())
    store = useNotificationStore()
  })

  const mountFor = (command, props = {}) =>
    mount(CommandFeedback, { props: { command, ...props } })

  const messages = () => store.items.map(item => item.message)

  it('says nothing about a command that has not been sent', () => {
    mountFor({ pending: false, error: null, result: null })

    expect(store.items).toEqual([])
  })

  it('announces an accepted command', async () => {
    const command = { pending: false, error: null, result: null }
    const view = mountFor(command)

    await view.setProps({ command: { ...command, result: { resourceId: 42 } } })

    expect(messages()).toEqual(['Angenommen.'])
    expect(store.items[0].type).toBe('success')
  })

  it('names the new id only where the caller has to act on it', async () => {
    const command = { pending: false, error: null, result: null }
    const view = mountFor(command, { showResourceId: true })

    await view.setProps({ command: { ...command, result: { resourceId: 42 } } })

    // B-21: a new participant's id has to be typed into the realm by hand
    expect(messages()).toEqual(['Angenommen. Neue ID: #42'])
  })

  it('passes the rule that said no through unchanged', async () => {
    const command = { pending: false, error: null, result: null }
    const view = mountFor(command)

    await view.setProps({
      command: { ...command, error: 'Laufen darf immer nur ein Tippjahr.' }
    })

    expect(messages()).toEqual(['Laufen darf immer nur ein Tippjahr.'])
    expect(store.items[0].type).toBe('error')
  })

  it('renders nothing where it stands', () => {
    const view = mountFor({ pending: false, error: null, result: null })

    expect(view.text()).toBe('')
  })
})
