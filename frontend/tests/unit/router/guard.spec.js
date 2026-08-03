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
 * visitor must be handed to Keycloak, and a non-admin must never even reach
 * an admin view.
 */
describe('router navigation guard', () => {
  beforeEach(async () => {
    vi.clearAllMocks()

    // `clearAllMocks` forgets the calls, not the implementations - so without
    // these two lines the parking navigation below would still be judged
    // against whatever session the previous test set up.
    keycloakService.initKeycloak.mockResolvedValue(false)
    keycloakService.getUserInfo.mockReturnValue(null)

    // Parking on /login rather than on / : every other route now aborts its
    // navigation and hands the browser to Keycloak, which would leave the
    // router on its start location and each test pushing from nowhere.
    setActivePinia(createPinia())
    await router.push('/login')
    await router.isReady()

    // A second, clean store for the test itself. The parking run above went
    // through the guard, and the guard awaits the store's Keycloak bootstrap -
    // which is memoised. Handing that store to the test would freeze it as
    // anonymous no matter what session the test then sets up.
    setActivePinia(createPinia())
  })

  async function loginAs(roles) {
    keycloakService.initKeycloak.mockResolvedValue(true)
    keycloakService.getUserInfo.mockReturnValue({
      username: 'user', email: 'user@example.com', roles, participantId: 1, subject: 's1'
    })
    await useAuthStore().initKeycloak()
  }

  it('hands an anonymous visitor to Keycloak instead of showing a page in between', async () => {
    await router.push('/bet-row')

    expect(keycloakService.login).toHaveBeenCalled()
    // And the route was not entered on the way out - the browser is leaving
    // this document, so rendering the requested view first would flash a page
    // the visitor is not allowed to see.
    expect(router.currentRoute.value.path).not.toBe('/bet-row')
  })

  it('asks Keycloak to come back to the route that was requested', async () => {
    // Without this the deep link dies in the redirect: Keycloak would return
    // to whichever page the login started from, not to the one asked for.
    await router.push('/fees')

    expect(keycloakService.login).toHaveBeenCalledWith(
      expect.objectContaining({ redirectUri: expect.stringMatching(/\/fees$/) })
    )
  })

  it('still shows /login itself to an anonymous visitor', async () => {
    // beforeEach navigated here anonymously - the state a logout leaves
    // behind. The one route that must not redirect: bouncing it to Keycloak
    // would make "Abmelden" look like it had done nothing.
    expect(router.currentRoute.value.path).toBe('/login')
    expect(keycloakService.login).not.toHaveBeenCalled()
  })

  it('keeps a deep link when Keycloak only reports back after the navigation began', async () => {
    // The regression this guards: Vue Router runs its first navigation from
    // inside `app.use(router)`, before main.js has awaited the Keycloak
    // bootstrap. The guard used to judge that navigation as anonymous, send
    // it to /login, and the session then arrived too late to matter - every
    // deep link and every reload of a protected page landed on HOME.
    setActivePinia(createPinia())

    let reportSession
    keycloakService.initKeycloak.mockReturnValue(new Promise(resolve => {
      reportSession = resolve
    }))
    keycloakService.getUserInfo.mockReturnValue({
      username: 'user', email: 'user@example.com', roles: ['user'], participantId: 1, subject: 's1'
    })

    // Deliberately not awaited: the navigation has to be in flight while
    // Keycloak is still undecided, which is the situation at app start.
    const navigation = router.push('/fees')
    reportSession(true)
    await navigation

    expect(router.currentRoute.value.path).toBe('/fees')
    // Its own timeout: this case builds the router, holds Keycloak's promise
    // unresolved across a navigation and only then settles it, which lands
    // around three seconds against the five-second default. On a loaded
    // machine that crosses the line and fails for no reason anyone can act on.
  }, 20000)

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
    // Navigate away from /login first: beforeEach parked us there, and
    // pushing straight back to the route we are already on would be a no-op
    // duplicate navigation that never re-runs the guard at all.
    await router.push('/bet-row')

    await router.push('/login')

    expect(router.currentRoute.value.path).toBe('/bet-row')
  })
})
