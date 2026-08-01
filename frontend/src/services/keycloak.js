import Keycloak from 'keycloak-js'
import { keycloakConfig } from '@/support/runtimeConfig'

// Runtime first, then what Vite baked in, then a development default - see
// support/runtimeConfig.js for why the SPA cannot simply read import.meta.env
// here if one built image is to serve more than one environment.
const keycloak = new Keycloak(keycloakConfig())

// Initialize Keycloak
let initPromise = null

export const initKeycloak = () => {
  if (initPromise) {
    return initPromise
  }

  initPromise = keycloak.init({
    onLoad: 'check-sso',
    silentCheckSsoRedirectUri: window.location.origin + '/silent-check-sso.html',
    pkceMethod: 'S256',
    checkLoginIframe: false
  })

  return initPromise
}

// Login
export const login = () => {
  return keycloak.login()
}

// Logout
export const logout = () => {
  return keycloak.logout()
}

// Check if user is authenticated
export const isAuthenticated = () => {
  return keycloak.authenticated
}

// Get access token
export const getToken = () => {
  return keycloak.token
}

// Get user info
export const getUserInfo = () => {
  if (!keycloak.authenticated) {
    return null
  }

  return {
    username: keycloak.tokenParsed?.preferred_username,
    email: keycloak.tokenParsed?.email,
    firstName: keycloak.tokenParsed?.given_name,
    lastName: keycloak.tokenParsed?.family_name,
    participantId: keycloak.tokenParsed?.participant_id,
    roles: keycloak.tokenParsed?.realm_access?.roles || [],
    subject: keycloak.tokenParsed?.sub
  }
}

// Check if user has role
export const hasRole = (role) => {
  if (!keycloak.authenticated) {
    return false
  }

  const roles = keycloak.tokenParsed?.realm_access?.roles || []
  return roles.includes(role)
}

// Get all roles
export const getRoles = () => {
  if (!keycloak.authenticated) {
    return []
  }

  return keycloak.tokenParsed?.realm_access?.roles || []
}

// Update token (refresh if needed)
export const updateToken = (minValidity = 5) => {
  return keycloak.updateToken(minValidity)
}

// Register token refresh handler
export const onTokenExpired = (callback) => {
  keycloak.onTokenExpired = callback
}

// Get Keycloak instance (for advanced usage)
export const getKeycloakInstance = () => {
  return keycloak
}

export default {
  initKeycloak,
  login,
  logout,
  isAuthenticated,
  getToken,
  getUserInfo,
  hasRole,
  getRoles,
  updateToken,
  onTokenExpired,
  getKeycloakInstance
}
