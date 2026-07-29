import axios from 'axios'
import * as keycloakService from './keycloak'

const client = axios.create({
  baseURL: '/api',
  headers: {
    'Content-Type': 'application/json'
  }
})

// Add auth token to requests
client.interceptors.request.use(
  async config => {
    if (keycloakService.isAuthenticated()) {
      try {
        // Update token if needed (refresh)
        await keycloakService.updateToken(5)
        const token = keycloakService.getToken()

        if (token) {
          config.headers.Authorization = `Bearer ${token}`
        }
      } catch (error) {
        console.error('Failed to get token:', error)
      }
    }

    return config
  },
  error => Promise.reject(error)
)

// Handle auth errors
client.interceptors.response.use(
  response => response,
  error => {
    // Only 401 means the token was rejected. A 503 says the API could not reach
    // Keycloak at all - throwing the token away and logging in again would send
    // the user to the very service we already know is down.
    if (error.response?.status === 401) {
      keycloakService.login()
    }
    return Promise.reject(error)
  }
)

/**
 * Drops empty query parameters instead of sending `?status=`.
 *
 * The API reads an absent parameter as "no filter" and an empty one as a filter
 * for the empty string, so a blank select must not reach the URL at all.
 */
const query = (values = {}) => ({
  params: Object.fromEntries(
    Object.entries(values).filter(([, value]) => value !== null && value !== undefined && value !== '')
  )
})

/**
 * The Idempotency-Key header (OPS-02) that makes a command repeatable.
 *
 * The key is passed in rather than minted here: only the caller knows whether a
 * second call is a retry of the same intent or a new command. See
 * `composables/useCommand.js`, which owns that decision.
 */
const command = (idempotencyKey) =>
  idempotencyKey ? { headers: { 'Idempotency-Key': idempotencyKey } } : {}

export default {
  health() {
    return client.get('/health')
  },

  // --- Participant, read only (B-01 to B-04) ---

  getBetRow(participantId, betPeriodId = null) {
    return client.get(`/participants/${participantId}/bet-row`, query({ betPeriodId }))
  },

  getMemberships(participantId, tippYearId = null) {
    return client.get(`/participants/${participantId}/memberships`, query({ tippYearId }))
  },

  getFees(participantId, filters = {}) {
    return client.get(`/participants/${participantId}/fees`, query(filters))
  },

  getPayoutShare(participantId, tippYearId = null) {
    return client.get(`/participants/${participantId}/payout-share`, query({ tippYearId }))
  },

  // --- Tipp year, shared result (B-05) ---

  getDraws(tippYearId, filters = {}) {
    return client.get(`/tipp-years/${tippYearId}/draws`, query(filters))
  },

  // --- Operations (OPS-01) ---

  // Not admin-only: whoever issued the command may look up what became of it.
  getCommandStatus(commandId) {
    return client.get(`/commands/${commandId}`)
  },

  admin: {
    // --- Bet rows (B-06) ---

    assignBetRow(participantId, data, idempotencyKey) {
      return client.put(
        `/admin/participants/${participantId}/bet-row`,
        data,
        command(idempotencyKey)
      )
    },

    // --- Fees (B-07) ---

    getFees(filters = {}) {
      return client.get('/admin/fees', query(filters))
    },

    recordFeePayment(feeId, data, idempotencyKey) {
      return client.put(`/admin/fees/${feeId}/payment`, data, command(idempotencyKey))
    },

    // --- Draws (B-08, B-09) ---

    recordDraw(data, idempotencyKey) {
      return client.post('/admin/draws', data, command(idempotencyKey))
    },

    recordDrawWinnings(drawId, data, idempotencyKey) {
      return client.put(`/admin/draws/${drawId}/winnings`, data, command(idempotencyKey))
    },

    // --- Tipp year (B-10 to B-14) ---

    getTippYears(status = null) {
      return client.get('/admin/tipp-years', query({ status }))
    },

    createTippYear(data, idempotencyKey) {
      return client.post('/admin/tipp-years', data, command(idempotencyKey))
    },

    getBetPeriods(tippYearId) {
      return client.get(`/admin/tipp-years/${tippYearId}/bet-periods`)
    },

    createBetPeriod(tippYearId, data, idempotencyKey) {
      return client.post(
        `/admin/tipp-years/${tippYearId}/bet-periods`,
        data,
        command(idempotencyKey)
      )
    },

    addMember(tippYearId, data, idempotencyKey) {
      return client.post(`/admin/tipp-years/${tippYearId}/members`, data, command(idempotencyKey))
    },

    submitTicket(tippYearId, data, idempotencyKey) {
      return client.post(`/admin/tipp-years/${tippYearId}/tickets`, data, command(idempotencyKey))
    },

    distributePayout(tippYearId, data, idempotencyKey) {
      return client.post(`/admin/tipp-years/${tippYearId}/payout`, data, command(idempotencyKey))
    },

    // --- Operations (OPS-03, OPS-04) ---

    getAuditTrail(aggregateType, aggregateId) {
      return client.get(`/admin/audit/${aggregateType}/${aggregateId}`)
    },

    getProjections() {
      return client.get('/admin/projections')
    },

    // Not a command: a rebuild changes no domain state, so it carries no key.
    rebuildProjection(name) {
      return client.post(`/admin/projections/${name}/rebuild`)
    }
  }
}
