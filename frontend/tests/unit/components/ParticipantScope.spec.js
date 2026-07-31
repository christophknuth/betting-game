import { describe, it, expect, beforeEach, vi } from 'vitest'
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

  beforeEach(() => {
    pinia = createPinia()
    setActivePinia(pinia)
  })

  it('renders the slot when the token carries a participant_id', () => {
    const store = useAuthStore()
    store.participantId = 7

    const wrapper = mount(ParticipantScope, {
      global: { plugins: [pinia] },
      slots: { default: '<p id="content">bet row data</p>' }
    })

    expect(wrapper.find('#content').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('participant_id')
  })

  it('shows a note instead of the slot when participant_id is missing', () => {
    const store = useAuthStore()
    store.participantId = null
    store.roles = ['user']

    const wrapper = mount(ParticipantScope, {
      global: { plugins: [pinia] },
      slots: { default: '<p id="content">bet row data</p>' }
    })

    expect(wrapper.find('#content').exists()).toBe(false)
    expect(wrapper.text()).toContain('participant_id')
  })

  it('adds the realm-configuration hint only when the token has no roles either', () => {
    const store = useAuthStore()
    store.participantId = null
    store.roles = []

    const wrapper = mount(ParticipantScope, {
      global: { plugins: [pinia] },
      slots: { default: '<p id="content">bet row data</p>' }
    })

    expect(wrapper.text()).toContain('Client Scopes')
  })

  it('omits the realm-configuration hint when roles are present', () => {
    const store = useAuthStore()
    store.participantId = null
    store.roles = ['user']

    const wrapper = mount(ParticipantScope, {
      global: { plugins: [pinia] },
      slots: { default: '<p id="content">bet row data</p>' }
    })

    expect(wrapper.text()).not.toContain('Client Scopes')
  })
})
