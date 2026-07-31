import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('@/services/keycloak', () => ({
  initKeycloak: vi.fn(),
  getUserInfo: vi.fn(),
  getToken: vi.fn(() => 'token-123'),
  onTokenExpired: vi.fn(),
  updateToken: vi.fn(),
  login: vi.fn(),
  logout: vi.fn()
}))

// Imported after the mock so the store picks up the mocked module.
import { useAuthStore } from '@/stores/auth'
import * as keycloakService from '@/services/keycloak'

describe('auth store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  describe('hasRole / isAdmin (B-17: the admin area is role-gated)', () => {
    it('recognises the admin role', () => {
      const store = useAuthStore()
      store.roles = ['user', 'admin']

      expect(store.isAdmin()).toBe(true)
      expect(store.hasRole('user')).toBe(true)
    })

    it('rejects a token that only carries the participant role', () => {
      const store = useAuthStore()
      store.roles = ['user']

      expect(store.isAdmin()).toBe(false)
    })

    it('rejects a token with no roles at all', () => {
      const store = useAuthStore()

      expect(store.isAdmin()).toBe(false)
      expect(store.hasRole('user')).toBe(false)
    })
  })

  describe('displayName', () => {
    it('prefers the username', () => {
      const store = useAuthStore()
      store.username = 'jane'
      store.email = 'jane@example.com'

      expect(store.displayName).toBe('jane')
    })

    it('falls back to the email when there is no username', () => {
      const store = useAuthStore()
      store.email = 'jane@example.com'

      expect(store.displayName).toBe('jane@example.com')
    })

    it('falls back to "User" when neither is known', () => {
      const store = useAuthStore()

      expect(store.displayName).toBe('User')
    })
  })

  describe('initKeycloak', () => {
    it('adopts the user info from a successful, already-authenticated session', async () => {
      keycloakService.initKeycloak.mockResolvedValue(true)
      keycloakService.getUserInfo.mockReturnValue({
        username: 'jane',
        email: 'jane@example.com',
        roles: ['user', 'admin'],
        participantId: 7,
        subject: 'sub-1'
      })

      const store = useAuthStore()
      const isAuth = await store.initKeycloak()

      expect(isAuth).toBe(true)
      expect(store.isAuthenticated).toBe(true)
      expect(store.participantId).toBe(7)
      expect(store.isAdmin()).toBe(true)
    })

    it('stays unauthenticated when there is no session, without touching participantId', async () => {
      keycloakService.initKeycloak.mockResolvedValue(false)

      const store = useAuthStore()
      const isAuth = await store.initKeycloak()

      expect(isAuth).toBe(false)
      expect(store.isAuthenticated).toBe(false)
      expect(store.participantId).toBeNull()
      expect(keycloakService.getUserInfo).not.toHaveBeenCalled()
    })

    it('reports false when Keycloak itself throws, instead of leaving the app hanging', async () => {
      keycloakService.initKeycloak.mockRejectedValue(new Error('adapter init failed'))

      const store = useAuthStore()
      const isAuth = await store.initKeycloak()

      expect(isAuth).toBe(false)
      expect(store.isAuthenticated).toBe(false)
    })
  })

  describe('logout', () => {
    it('clears all local state on a normal logout', async () => {
      keycloakService.initKeycloak.mockResolvedValue(true)
      keycloakService.getUserInfo.mockReturnValue({
        username: 'jane', email: 'j@x.com', roles: ['admin'], participantId: 3, subject: 's1'
      })
      keycloakService.logout.mockResolvedValue(undefined)

      const store = useAuthStore()
      await store.initKeycloak()
      await store.logout()

      expect(store.isAuthenticated).toBe(false)
      expect(store.participantId).toBeNull()
      expect(store.roles).toEqual([])
      expect(store.token).toBeNull()
    })

    it('still clears local state even when the Keycloak logout call itself fails', async () => {
      keycloakService.initKeycloak.mockResolvedValue(true)
      keycloakService.getUserInfo.mockReturnValue({
        username: 'jane', email: 'j@x.com', roles: ['admin'], participantId: 3, subject: 's1'
      })
      keycloakService.logout.mockRejectedValue(new Error('network error'))

      const store = useAuthStore()
      await store.initKeycloak()
      await store.logout()

      // A dead Keycloak must not strand the SPA in an authenticated-looking state.
      expect(store.isAuthenticated).toBe(false)
      expect(store.participantId).toBeNull()
    })
  })
})
