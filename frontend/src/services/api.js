import axios from 'axios'
import * as keycloakService from './keycloak'

const api = axios.create({
  baseURL: '/api',
  headers: {
    'Content-Type': 'application/json'
  }
})

// Add auth token to requests
api.interceptors.request.use(
  async config => {
    // Get token from Keycloak
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
api.interceptors.response.use(
  response => response,
  error => {
    if (error.response?.status === 401) {
      // Redirect to Keycloak login
      keycloakService.login()
    }
    return Promise.reject(error)
  }
)

export default {
  // Predictions
  getPredictions(participantId, params = {}) {
    return api.get(`/participants/${participantId}/predictions`, { params })
  },

  getPrediction(participantId, predictionId) {
    return api.get(`/participants/${participantId}/predictions/${predictionId}`)
  },

  submitPrediction(participantId, eventId, predictionData) {
    return api.post(`/participants/${participantId}/events/${eventId}/predictions`, {
      predictionData
    })
  },

  updatePrediction(participantId, predictionId, predictionData) {
    return api.put(`/participants/${participantId}/predictions/${predictionId}`, {
      predictionData
    })
  },

  // Scores
  getScores(participantId, bettingGameId = null) {
    const params = bettingGameId ? { bettingGameId } : {}
    return api.get(`/participants/${participantId}/scores`, { params })
  },

  // Participations
  getParticipations(participantId, status = null) {
    const params = status ? { status } : {}
    return api.get(`/participants/${participantId}/participations`, { params })
  },

  joinGame(participantId, bettingGameId, acceptTerms = true) {
    return api.post(`/participants/${participantId}/games/${bettingGameId}/participation`, {
      acceptTerms
    })
  },

  leaveGame(participantId, bettingGameId) {
    return api.delete(`/participants/${participantId}/games/${bettingGameId}/participation`)
  },

  // Admin endpoints (if needed)
  admin: {
    getAllPredictions(params = {}) {
      return api.get('/admin/predictions', { params })
    },

    getAllGames(params = {}) {
      return api.get('/admin/games', { params })
    },

    createGame(gameData) {
      return api.post('/admin/games', gameData)
    },

    endGame(bettingGameId, data = {}) {
      return api.post(`/admin/games/${bettingGameId}/end`, data)
    },

    recordResult(eventId, resultData) {
      return api.post(`/admin/events/${eventId}/results`, resultData)
    },

    calculateScores(eventId) {
      return api.post(`/admin/events/${eventId}/scores/calculate`)
    },

    awardScore(participantId, data) {
      return api.post(`/admin/participants/${participantId}/scores`, data)
    }
  }
}
