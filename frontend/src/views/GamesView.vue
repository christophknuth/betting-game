<template>
  <div class="games-view">
    <h1>My Games</h1>

    <div v-if="loading" class="loading">
      Loading games...
    </div>

    <div v-else-if="error" class="error">
      {{ error }}
    </div>

    <div v-else>
      <!-- Active Participations -->
      <section class="section">
        <h2>🎮 Active Participations</h2>
        
        <div v-if="activeGames.length === 0" class="empty-state">
          <p>No active games</p>
          <p>Join a game below to get started!</p>
        </div>

        <div v-else class="games-grid">
          <div
            v-for="game in activeGames"
            :key="game.participationId"
            class="game-card active"
          >
            <div class="card-header">
              <h3>{{ game.gameName }}</h3>
              <span class="badge active">Active</span>
            </div>

            <div class="card-body">
              <div class="info-row">
                <span class="label">Joined:</span>
                <span>{{ formatDate(game.joinedAt) }}</span>
              </div>
              <div class="info-row">
                <span class="label">Status:</span>
                <span>{{ game.gameStatus }}</span>
              </div>
              <div v-if="game.currentPoints !== null" class="info-row">
                <span class="label">Points:</span>
                <span class="points">{{ game.currentPoints }}</span>
              </div>
            </div>

            <div class="card-actions">
              <button
                @click="showLeaveModal(game)"
                class="btn-danger"
              >
                🚪 Leave Game
              </button>
            </div>
          </div>
        </div>
      </section>

      <!-- Join New Game -->
      <section class="section">
        <h2>➕ Join New Game</h2>
        
        <div class="join-form-card">
          <form @submit.prevent="joinGame">
            <div class="form-group">
              <label for="gameId">Game ID</label>
              <input
                id="gameId"
                v-model.number="joinForm.gameId"
                type="number"
                required
                placeholder="Enter game ID"
              />
            </div>

            <div class="form-group">
              <label class="checkbox-label">
                <input
                  v-model="joinForm.acceptTerms"
                  type="checkbox"
                  required
                />
                <span>I accept the game terms and conditions</span>
              </label>
            </div>

            <button
              type="submit"
              class="btn-primary"
              :disabled="joiningGame || !joinForm.acceptTerms"
            >
              {{ joiningGame ? 'Joining...' : 'Join Game' }}
            </button>
          </form>

          <div v-if="joinError" class="error-message">
            {{ joinError }}
          </div>

          <div v-if="joinSuccess" class="success-message">
            ✅ Successfully joined the game!
          </div>
        </div>
      </section>

      <!-- Past Participations -->
      <section v-if="pastGames.length > 0" class="section">
        <h2>📜 Past Participations</h2>
        
        <div class="games-grid">
          <div
            v-for="game in pastGames"
            :key="game.participationId"
            class="game-card past"
          >
            <div class="card-header">
              <h3>{{ game.gameName }}</h3>
              <span class="badge past">{{ game.gameStatus }}</span>
            </div>

            <div class="card-body">
              <div class="info-row">
                <span class="label">Joined:</span>
                <span>{{ formatDate(game.joinedAt) }}</span>
              </div>
              <div v-if="game.leftAt" class="info-row">
                <span class="label">Left:</span>
                <span>{{ formatDate(game.leftAt) }}</span>
              </div>
              <div v-if="game.finalPoints !== null" class="info-row">
                <span class="label">Final Points:</span>
                <span class="points">{{ game.finalPoints }}</span>
              </div>
            </div>
          </div>
        </div>
      </section>
    </div>

    <!-- Leave Confirmation Modal -->
    <div v-if="leaveModal.show" class="modal-overlay" @click="closeLeaveModal">
      <div class="modal" @click.stop>
        <h3>Leave Game?</h3>
        <p>Are you sure you want to leave "{{ leaveModal.game?.gameName }}"?</p>
        <p class="warning">This action cannot be undone.</p>

        <div class="modal-actions">
          <button @click="closeLeaveModal" class="btn-cancel">
            Cancel
          </button>
          <button
            @click="confirmLeaveGame"
            class="btn-danger"
            :disabled="leavingGame"
          >
            {{ leavingGame ? 'Leaving...' : 'Leave Game' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import api from '@/services/api'

const authStore = useAuthStore()

const participations = ref([])
const loading = ref(false)
const error = ref(null)

const joinForm = ref({
  gameId: null,
  acceptTerms: false
})
const joiningGame = ref(false)
const joinError = ref(null)
const joinSuccess = ref(false)

const leaveModal = ref({
  show: false,
  game: null
})
const leavingGame = ref(false)

const activeGames = computed(() => 
  participations.value.filter(p => p.gameStatus === 'active')
)

const pastGames = computed(() => 
  participations.value.filter(p => p.gameStatus !== 'active')
)

const loadParticipations = async () => {
  loading.value = true
  error.value = null

  try {
    const response = await api.getParticipations(authStore.participantId)
    participations.value = response.data.participations || []
  } catch (err) {
    error.value = 'Failed to load games. Please try again.'
    console.error('Error loading participations:', err)
  } finally {
    loading.value = false
  }
}

const joinGame = async () => {
  joiningGame.value = true
  joinError.value = null
  joinSuccess.value = false

  try {
    await api.joinGame(
      authStore.participantId,
      joinForm.value.gameId,
      joinForm.value.acceptTerms
    )

    joinSuccess.value = true
    joinForm.value.gameId = null
    joinForm.value.acceptTerms = false

    // Reload participations
    setTimeout(() => {
      loadParticipations()
      joinSuccess.value = false
    }, 2000)
  } catch (err) {
    joinError.value = err.response?.data?.message || 'Failed to join game'
    console.error('Error joining game:', err)
  } finally {
    joiningGame.value = false
  }
}

const showLeaveModal = (game) => {
  leaveModal.value.show = true
  leaveModal.value.game = game
}

const closeLeaveModal = () => {
  leaveModal.value.show = false
  leaveModal.value.game = null
}

const confirmLeaveGame = async () => {
  if (!leaveModal.value.game) return

  leavingGame.value = true

  try {
    await api.leaveGame(
      authStore.participantId,
      leaveModal.value.game.bettingGameId
    )

    closeLeaveModal()
    loadParticipations()
  } catch (err) {
    alert('Failed to leave game: ' + (err.response?.data?.message || err.message))
    console.error('Error leaving game:', err)
  } finally {
    leavingGame.value = false
  }
}

const formatDate = (dateString) => {
  return new Date(dateString).toLocaleString('de-DE', {
    year: 'numeric',
    month: 'short',
    day: 'numeric'
  })
}

onMounted(() => {
  loadParticipations()
})
</script>

<style scoped>
.games-view {
  padding: 2rem 0;
}

h1 {
  color: #1f2937;
  font-size: 2rem;
  margin-bottom: 2rem;
}

h2 {
  color: #1f2937;
  font-size: 1.5rem;
  margin-bottom: 1.5rem;
}

.section {
  margin-bottom: 3rem;
}

.loading,
.error {
  text-align: center;
  padding: 3rem;
  color: #666;
}

.error {
  color: #dc2626;
}

.empty-state {
  text-align: center;
  padding: 3rem;
  color: #666;
  background: white;
  border-radius: 12px;
  border: 2px dashed #e5e7eb;
}

.games-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
  gap: 1.5rem;
}

.game-card {
  background: white;
  border-radius: 12px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.1);
  overflow: hidden;
  transition: transform 0.2s, box-shadow 0.2s;
}

.game-card:hover {
  transform: translateY(-4px);
  box-shadow: 0 4px 16px rgba(0,0,0,0.15);
}

.game-card.active {
  border: 2px solid #10b981;
}

.game-card.past {
  opacity: 0.8;
}

.card-header {
  padding: 1.25rem;
  background: #f9fafb;
  border-bottom: 1px solid #e5e7eb;
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.card-header h3 {
  margin: 0;
  color: #1f2937;
  font-size: 1.125rem;
}

.badge {
  padding: 0.25rem 0.75rem;
  border-radius: 12px;
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
}

.badge.active {
  background: #d1fae5;
  color: #065f46;
}

.badge.past {
  background: #f3f4f6;
  color: #6b7280;
}

.card-body {
  padding: 1.25rem;
}

.info-row {
  display: flex;
  justify-content: space-between;
  padding: 0.5rem 0;
  border-bottom: 1px solid #f3f4f6;
  font-size: 0.875rem;
}

.info-row:last-child {
  border-bottom: none;
}

.info-row .label {
  color: #6b7280;
  font-weight: 500;
}

.points {
  color: #2563eb;
  font-weight: 700;
}

.card-actions {
  padding: 1rem 1.25rem;
  background: #f9fafb;
  border-top: 1px solid #e5e7eb;
  display: flex;
  justify-content: flex-end;
}

.join-form-card {
  background: white;
  padding: 2rem;
  border-radius: 12px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.1);
  max-width: 500px;
}

.form-group {
  margin-bottom: 1.5rem;
}

label {
  display: block;
  margin-bottom: 0.5rem;
  color: #374151;
  font-weight: 600;
}

input[type="number"] {
  width: 100%;
  padding: 0.75rem;
  border: 2px solid #e5e7eb;
  border-radius: 6px;
  font-size: 1rem;
  transition: border-color 0.2s;
}

input[type="number"]:focus {
  outline: none;
  border-color: #2563eb;
}

.checkbox-label {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  cursor: pointer;
  font-weight: normal;
}

.checkbox-label input[type="checkbox"] {
  width: 1.25rem;
  height: 1.25rem;
  cursor: pointer;
}

.btn-primary {
  width: 100%;
  padding: 0.75rem;
  background: #2563eb;
  color: white;
  border: none;
  border-radius: 6px;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.2s;
}

.btn-primary:hover:not(:disabled) {
  background: #1d4ed8;
}

.btn-primary:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.btn-danger {
  padding: 0.5rem 1rem;
  background: #ef4444;
  color: white;
  border: none;
  border-radius: 6px;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.2s;
}

.btn-danger:hover:not(:disabled) {
  background: #dc2626;
}

.btn-danger:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.error-message {
  margin-top: 1rem;
  padding: 1rem;
  background: #fee2e2;
  border: 1px solid #fca5a5;
  border-radius: 6px;
  color: #dc2626;
}

.success-message {
  margin-top: 1rem;
  padding: 1rem;
  background: #d1fae5;
  border: 1px solid #6ee7b7;
  border-radius: 6px;
  color: #065f46;
}

/* Modal */
.modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}

.modal {
  background: white;
  padding: 2rem;
  border-radius: 12px;
  box-shadow: 0 20px 60px rgba(0,0,0,0.3);
  max-width: 400px;
  width: 90%;
}

.modal h3 {
  margin: 0 0 1rem 0;
  color: #1f2937;
}

.modal p {
  margin: 0.5rem 0;
  color: #6b7280;
}

.modal .warning {
  color: #dc2626;
  font-weight: 500;
}

.modal-actions {
  display: flex;
  gap: 1rem;
  margin-top: 2rem;
}

.btn-cancel {
  flex: 1;
  padding: 0.75rem;
  background: white;
  color: #6b7280;
  border: 2px solid #e5e7eb;
  border-radius: 6px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s;
}

.btn-cancel:hover {
  background: #f3f4f6;
  border-color: #d1d5db;
}
</style>
