import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/services/api'
import * as keycloakService from '@/services/keycloak'

export const useAuthStore = defineStore('auth', () => {
  const token = ref(null)
  const participantId = ref(null)
  const username = ref(null)
  const email = ref(null)
  const roles = ref([])
  const subject = ref(null)
  const keycloakInitialized = ref(false)
  const authenticated = ref(false)

  // E1-01: what the API says about this account - `{ registered, status, … }`.
  // Null while nobody has asked, which is the case for every token that brings
  // its own participant_id claim.
  const registration = ref(null)

  const isAuthenticated = computed(() => authenticated.value)
  const displayName = computed(() => username.value || email.value || 'User')

  // Memoised so `ready()` below can await the very same initialisation the
  // app start kicked off, no matter which of the two runs first.
  let initPromise = null

  function initKeycloak() {
    initPromise ??= runInitialisation()

    return initPromise
  }

  /**
   * Resolves once Keycloak has had its say about the current session.
   *
   * The router guard has to await this. Vue Router runs its first navigation
   * inside `app.use(router)`, which happens before `main.js` gets to await
   * the Keycloak bootstrap - so without this the guard judged every deep link
   * as "not logged in", sent it to /login, and the requested route was gone
   * by the time the session came back.
   */
  function ready() {
    return initKeycloak()
  }

  async function runInitialisation() {
    try {
      const isAuth = await keycloakService.initKeycloak()
      keycloakInitialized.value = true

      if (isAuth) {
        authenticated.value = true
        updateUserInfo()

        // E1-01: an account that registered itself carries no participant_id
        // claim and never will. Asking the API closes that gap - and only for
        // the tokens that need it, since a realm that maps the attribute
        // answers from the token alone.
        await loadRegistration()

        // Setup token refresh
        keycloakService.onTokenExpired(() => {
          keycloakService.updateToken(30).then(() => {
            updateUserInfo()
          }).catch(() => {
            logout()
          })
        })
      }

      return isAuth
    } catch (error) {
      console.error('Keycloak initialization failed:', error)
      return false
    }
  }

  function updateUserInfo() {
    const userInfo = keycloakService.getUserInfo()
    
    if (userInfo) {
      token.value = keycloakService.getToken()
      participantId.value = userInfo.participantId || null
      username.value = userInfo.username
      email.value = userInfo.email
      roles.value = userInfo.roles
      subject.value = userInfo.subject
    }
  }

  /**
   * E1-01: which participant this account is, where the token does not say.
   *
   * The API resolves the same thing server-side from the token's subject; this
   * is the interface learning it, so the participant views know whether they
   * have somebody to show - and so `/register` can report where a registration
   * stands.
   *
   * A failure is not fatal. Somebody who is logged in but unknown to the
   * application is exactly the state this call describes, and the views handle
   * it already.
   */
  async function loadRegistration() {
    if (participantId.value) {
      return
    }

    try {
      const { data } = await api.getMyRegistration()

      registration.value = data

      // Only an approved participant identifies the account. A pending one is
      // a request, and letting the views open for it would show empty pages
      // instead of "waiting for approval".
      if (data.registered && data.status === 'active') {
        participantId.value = data.participantId
      }
    } catch (error) {
      console.warn('Could not ask the API about this account:', error)
    }
  }

  async function login() {
    try {
      await keycloakService.login()
    } catch (error) {
      console.error('Login failed:', error)
      throw error
    }
  }

  async function logout() {
    try {
      await keycloakService.logout()
    } catch (error) {
      console.error('Logout failed:', error)
    } finally {
      // Clear local state
      authenticated.value = false
      token.value = null
      participantId.value = null
      username.value = null
      email.value = null
      roles.value = []
      subject.value = null
      registration.value = null
    }
  }

  function hasRole(role) {
    return roles.value.includes(role)
  }

  function isAdmin() {
    return hasRole('admin')
  }

  return {
    // State
    token,
    participantId,
    username,
    email,
    roles,
    subject,
    registration,
    keycloakInitialized,
    
    // Computed
    isAuthenticated,
    displayName,
    
    // Actions
    initKeycloak,
    ready,
    updateUserInfo,
    loadRegistration,
    login,
    logout,
    hasRole,
    isAdmin
  }
})
