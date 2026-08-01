import { describe, it, expect, afterEach, vi } from 'vitest'
import { keycloakConfig } from '@/support/runtimeConfig'

/**
 * The whole point of this module is that one built image can serve more than
 * one environment. If the precedence is wrong, that fails in the least visible
 * way possible: the SPA talks to the wrong Keycloak, and the only symptom is a
 * token the API rejects with a 401 whose reason it deliberately does not say.
 */
describe('keycloakConfig', () => {
  afterEach(() => {
    delete window.__APP_CONFIG__
    vi.unstubAllEnvs()
  })

  it('falls back to the development defaults when nothing is configured', () => {
    expect(keycloakConfig()).toEqual({
      url: 'http://localhost:8090',
      realm: 'betting-game',
      clientId: 'betting-game-frontend'
    })
  })

  it('uses what Vite baked in when there is no runtime config', () => {
    vi.stubEnv('VITE_KEYCLOAK_URL', 'https://build.example.com')

    expect(keycloakConfig().url).toBe('https://build.example.com')
  })

  it('lets the runtime config win over the built-in value', () => {
    // This is the case that makes one image deployable twice.
    vi.stubEnv('VITE_KEYCLOAK_URL', 'https://build.example.com')
    window.__APP_CONFIG__ = { keycloakUrl: 'https://runtime.example.com' }

    expect(keycloakConfig().url).toBe('https://runtime.example.com')
  })

  it('ignores an empty runtime value instead of pointing the SPA at nothing', () => {
    // An unset environment variable renders as an empty string in the
    // generated config.js, and that has to read as "not configured".
    vi.stubEnv('VITE_KEYCLOAK_URL', 'https://build.example.com')
    window.__APP_CONFIG__ = { keycloakUrl: '' }

    expect(keycloakConfig().url).toBe('https://build.example.com')
  })

  it('takes each value on its own rather than all or nothing', () => {
    window.__APP_CONFIG__ = { keycloakRealm: 'other-realm' }

    const config = keycloakConfig()

    expect(config.realm).toBe('other-realm')
    expect(config.url).toBe('http://localhost:8090')
    expect(config.clientId).toBe('betting-game-frontend')
  })

  it('survives a config.js that was never overwritten', () => {
    window.__APP_CONFIG__ = {}

    expect(keycloakConfig().realm).toBe('betting-game')
  })
})
