/**
 * Where the SPA gets its Keycloak coordinates from.
 *
 * Vite bakes `import.meta.env.VITE_*` into the bundle at build time, which is
 * fine for development but means a built image belongs to exactly one
 * environment - and then "one image for production" is not a thing you can
 * have. So the container writes a `config.js` from its environment at start,
 * and that is consulted first.
 *
 * The order is deliberate: runtime, then build time, then a development
 * default. A value that is present but empty counts as absent - an unset
 * environment variable renders as an empty string in the generated file, and
 * treating that as configuration would point the SPA at nothing.
 */

const DEFAULTS = {
  keycloakUrl: 'http://localhost:8090',
  keycloakRealm: 'betting-game',
  keycloakClientId: 'betting-game-frontend'
}

function fromRuntime(key) {
  const value = globalThis.window?.__APP_CONFIG__?.[key]

  return typeof value === 'string' && value !== '' ? value : null
}

function fromBuild(key) {
  const value = import.meta.env?.[key]

  return typeof value === 'string' && value !== '' ? value : null
}

export function keycloakConfig() {
  return {
    url: fromRuntime('keycloakUrl') ?? fromBuild('VITE_KEYCLOAK_URL') ?? DEFAULTS.keycloakUrl,
    realm: fromRuntime('keycloakRealm') ?? fromBuild('VITE_KEYCLOAK_REALM') ?? DEFAULTS.keycloakRealm,
    clientId: fromRuntime('keycloakClientId')
      ?? fromBuild('VITE_KEYCLOAK_CLIENT_ID')
      ?? DEFAULTS.keycloakClientId
  }
}
