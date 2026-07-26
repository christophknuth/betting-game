import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
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

  const isAuthenticated = computed(() => authenticated.value)
  const displayName = computed(() => username.value || email.value || 'User')

  async function initKeycloak() {
    try {
      const isAuth = await keycloakService.initKeycloak()
      keycloakInitialized.value = true

      if (isAuth) {
        authenticated.value = true
        updateUserInfo()
        
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
    keycloakInitialized,
    
    // Computed
    isAuthenticated,
    displayName,
    
    // Actions
    initKeycloak,
    updateUserInfo,
    login,
    logout,
    hasRole,
    isAdmin
  }
})
