import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

// The auth store imports services/keycloak.js, which constructs a real
// Keycloak adapter on module load. None of the tests below call login/logout,
// but mocking it keeps this test independent of the keycloak-js package.
vi.mock('@/services/keycloak', () => ({
  initKeycloak: vi.fn(),
  getUserInfo: vi.fn(),
  getToken: vi.fn(() => 'token'),
  onTokenExpired: vi.fn(),
  updateToken: vi.fn(),
  login: vi.fn(),
  logout: vi.fn()
}))

import ParticipantScope from '@/components/ParticipantScope.vue'
import { useAuthStore } from '@/stores/auth'

/**
 * The four participant views and DrawsView all need somebody to show data for.
 * The API derives identity from the token, never from the URL, so without a
 * participant behind it there is nobody - not even for an admin. A deliberate
 * access-control boundary rather than a loading state, and worth its own test.
 *
 * Since E1-01 the note is not a dead end: where the account has no participant,
 * registering is what creates one, and the component has to tell the two
 * situations apart - nobody has asked yet, or the answer is still pending.
 */
describe('ParticipantScope', () => {
  let pinia

  function mountScope() {
    return mount(ParticipantScope, {
      global: {
        plugins: [pinia],
        stubs: { RouterLink: { template: '<a><slot /></a>' } }
      },
      slots: { default: '<p id="content">bet row data</p>' }
    })
  }

  beforeEach(() => {
    pinia = createPinia()
    setActivePinia(pinia)
    vi.spyOn(console, 'warn').mockImplementation(() => {})
    vi.spyOn(console, 'info').mockImplementation(() => {})
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('renders the slot when the token carries a participant_id', () => {
    const store = useAuthStore()
    store.participantId = 7

    const wrapper = mountScope()

    expect(wrapper.find('#content').exists()).toBe(true)
    expect(console.warn).not.toHaveBeenCalled()
  })

  it('renders the slot for an account resolved without a claim (E1-01)', () => {
    // What loadRegistration() leaves behind after an approval: the token still
    // carries no claim, the store knows the participant all the same.
    const store = useAuthStore()
    store.participantId = 4
    store.registration = { registered: true, status: 'active', participantId: 4 }

    expect(mountScope().find('#content').exists()).toBe(true)
  })

  it('offers the registration where the account has no participant', () => {
    const store = useAuthStore()
    store.participantId = null
    store.roles = ['user']
    store.registration = { registered: false }

    const wrapper = mountScope()

    expect(wrapper.find('#content').exists()).toBe(false)
    expect(wrapper.text()).toContain('Du spielst noch nicht mit')
    expect(wrapper.text()).toContain('Jetzt anmelden')
  })

  it('reports the wait instead of offering the form again', () => {
    const store = useAuthStore()
    store.participantId = null
    store.roles = ['user']
    store.registration = { registered: true, status: 'pending', participantId: 4 }

    const wrapper = mountScope()

    expect(wrapper.text()).toContain('Anmeldung wird noch geprüft')
    expect(wrapper.text()).not.toContain('Jetzt anmelden')
  })

  it('keeps the diagnosis out of the interface and puts it in the console', () => {
    // The panel used to name the missing claim and the realm's client scopes.
    // Neither is something the person reading it can act on, so the interface
    // offers the registration and the detail goes where a debugger will look.
    const store = useAuthStore()
    store.participantId = null
    store.roles = ['user']
    store.registration = { registered: false }

    const wrapper = mountScope()

    expect(wrapper.text()).not.toContain('participant_id')
    expect(console.info).toHaveBeenCalledWith(expect.stringContaining('participant_id'))
  })

  it('blames the realm rather than the user when the token has no roles either', () => {
    const store = useAuthStore()
    store.participantId = null
    store.roles = []

    mountScope()

    expect(console.warn).toHaveBeenCalledWith(expect.stringContaining('client scopes'))
  })

  it('does not blame the realm when roles are present', () => {
    const store = useAuthStore()
    store.participantId = null
    store.roles = ['user']
    store.registration = { registered: false }

    mountScope()

    expect(console.warn).not.toHaveBeenCalledWith(expect.stringContaining('client scopes'))
  })

  it('says nothing in the console once a registration exists', () => {
    // A missing claim is the normal case for a self-registered account, not a
    // fault worth a console line on every page.
    const store = useAuthStore()
    store.participantId = null
    store.roles = ['user']
    store.registration = { registered: true, status: 'pending', participantId: 4 }

    mountScope()

    expect(console.info).not.toHaveBeenCalled()
  })
})
