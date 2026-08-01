import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, RouterLinkStub } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

// The auth store imports services/keycloak.js, which builds a real adapter on
// module load. Nothing here logs in, but mocking keeps the test independent of
// the keycloak-js package.
vi.mock('@/services/keycloak', () => ({
  initKeycloak: vi.fn(),
  getUserInfo: vi.fn(),
  getToken: vi.fn(() => 'token'),
  onTokenExpired: vi.fn(),
  updateToken: vi.fn(),
  login: vi.fn(),
  logout: vi.fn()
}))

import ParticipantLayout from '@/layouts/ParticipantLayout.vue'
import { useAuthStore } from '@/stores/auth'

/**
 * The participant area and the admin area are separate places, and the
 * "Verwaltung" link is the single door between them (B-17). The router guard
 * and the API both turn a non-admin away from /admin/*, so this is not the
 * security boundary - but offering a door that leads to a bounce is its own
 * defect, and it is the sort that only shows up when someone without the role
 * logs in.
 */
describe('ParticipantLayout', () => {
  let pinia

  function mountLayout() {
    return mount(ParticipantLayout, {
      global: {
        plugins: [pinia],
        stubs: { RouterLink: RouterLinkStub, RouterView: true }
      }
    })
  }

  function linkTargets(wrapper) {
    return wrapper.findAllComponents(RouterLinkStub).map(link => link.props('to'))
  }

  beforeEach(() => {
    pinia = createPinia()
    setActivePinia(pinia)
  })

  it('offers the admin door to an admin', () => {
    const store = useAuthStore()
    store.roles = ['user', 'admin']

    const wrapper = mountLayout()

    expect(linkTargets(wrapper)).toContain('/admin')
    expect(wrapper.text()).toContain('Verwaltung')
  })

  it('hides the admin door from a participant without the role', () => {
    const store = useAuthStore()
    store.roles = ['user']

    const wrapper = mountLayout()

    expect(linkTargets(wrapper)).not.toContain('/admin')
    expect(wrapper.text()).not.toContain('Verwaltung')
  })

  it('never carries the admin views in its own navigation', () => {
    const store = useAuthStore()
    store.roles = ['user', 'admin']

    const targets = linkTargets(mountLayout())

    // Everything below /admin belongs to the other layout. An admin link that
    // crept back in here would rebuild exactly the mixed bar this split
    // removed - the one where "Ziehungen" appeared twice.
    expect(targets.filter(to => to.startsWith('/admin/'))).toEqual([])
  })
})
