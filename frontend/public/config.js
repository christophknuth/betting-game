// Placeholder, and deliberately empty.
//
// The production container overwrites this file at start-up with values from
// its environment, which is what lets one image serve staging and production
// (see docker/docker-entrypoint.sh). An empty object means "nothing configured
// at runtime", and support/runtimeConfig.js then falls back to the VITE_*
// values Vite baked in, and finally to the development defaults.
//
// It lives in public/ rather than being generated so that `npm run dev` and a
// plain `npm run build` both have it - a missing file would be a 404 in the
// console on every page load.
window.__APP_CONFIG__ = {}
