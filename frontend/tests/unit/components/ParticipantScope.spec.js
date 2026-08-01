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
 * The four participant views and DrawsView all need the participant_id claim
 * (FRONTEND.md, "Der participant_id-Claim"): the API derives identity from
 * the token, never the URL, so without the claim there is no one whose data
 * could be shown - not even to an admin. This is a deliberate access-control
 * boundary, not a loading state, so it is worth pinning down as its own test
 * rather than trusting it to a manual click-through.
 */
describe('ParticipantScope', () => {
  let pinia

  function mountScope() {
    return mount(ParticipantScope, {
      global: { plugins: [pinia] },
      slots: { default: '<p id="content">bet row data</p>' }
    })
  }

  beforeEach(() => {
    pinia = createPinia()
    setActivePinia(pinia)
    vi.spyOn(console, 'warn').mockImplementation(() => {})
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

  it('shows a note instead of the slot when participant_id is missing', () => {
    const store = useAuthStore()
    store.participantId = null
    store.roles = ['user']

    const wrapper = mountScope()

    expect(wrapper.find('#content').exists()).toBe(false)
    expect(wrapper.text()).toContain('Administrator')
  })

  it('keeps the diagnosis out of the interface and puts it in the console', () => {
    // The panel used to name the missing claim and the realm's client scopes.
    // Neither is something the person reading it can act on - the fix is an
    // attribute only an administrator can set - so the interface says that, and
    // the detail goes where someone debugging will look.
    const store = useAuthStore()
    store.participantId = null
    store.roles = ['user']

    const wrapper = mountScope()

    expect(wrapper.text()).not.toContain('participant_id')
    expect(console.warn).toHaveBeenCalledWith(expect.stringContaining('participant_id'))
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

    mountScope()

    expect(console.warn).not.toHaveBeenCalledWith(expect.stringContaining('client scopes'))
  })
})
