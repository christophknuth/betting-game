import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('@/services/keycloak', () => ({
  initKeycloak: vi.fn(),
  getUserInfo: vi.fn(),
  getToken: vi.fn(() => 'token'),
  onTokenExpired: vi.fn(),
  updateToken: vi.fn(),
  login: vi.fn(),
  logout: vi.fn()
}))

import router from '@/router'
import { useAuthStore } from '@/stores/auth'
import * as keycloakService from '@/services/keycloak'

/**
 * The navigation guard is only the entrance - the API re-checks the role on
 * every admin route, which is where the real decision is (see FRONTEND.md).
 * But the entrance still has to hold for B-15/B-16/B-17: an anonymous
 * visitor must land on /login, and a non-admin must never even reach an
 * admin view.
 */
describe('router navigation guard', () => {
  beforeEach(async () => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    await router.push('/')
    await router.isReady()
  })

  async function loginAs(roles) {
    keycloakService.initKeycloak.mockResolvedValue(true)
    keycloakService.getUserInfo.mockReturnValue({
      username: 'user', email: 'user@example.com', roles, participantId: 1, subject: 's1'
    })
    await useAuthStore().initKeycloak()
  }

  it('sends an anonymous visitor to /login for a route that requires auth', async () => {
    await router.push('/bet-row')

    expect(router.currentRoute.value.path).toBe('/login')
  })

  it('lets an authenticated participant reach a participant-only route', async () => {
    await loginAs(['user'])

    await router.push('/bet-row')

    expect(router.currentRoute.value.path).toBe('/bet-row')
  })

  it('sends a non-admin participant home instead of into an admin route', async () => {
    await loginAs(['user'])

    await router.push('/admin/tipp-years')

    expect(router.currentRoute.value.path).toBe('/bet-row')
  })

  it('lets an admin reach an admin route', async () => {
    await loginAs(['user', 'admin'])

    await router.push('/admin/tipp-years')

    expect(router.currentRoute.value.path).toBe('/admin/tipp-years')
  })

  it('sends an already-authenticated user away from /login instead of showing it again', async () => {
    await loginAs(['user'])
    // Navigate away from /login first: pushing straight back to the route we
    // are already on (from the beforeEach redirect chain) would be a no-op
    // duplicate navigation that never re-runs the guard at all.
    await router.push('/bet-row')

    await router.push('/login')

    expect(router.currentRoute.value.path).toBe('/bet-row')
  })
})
