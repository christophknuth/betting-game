<template>
  <div class="admin-games-view">
    <div class="header">
      <h1>🎮 Admin - Games</h1>
      <button @click="showCreateModal = true" class="btn-primary">
        ➕ Create Game
      </button>
    </div>

    <div class="filters">
      <select v-model="statusFilter" @change="loadGames">
        <option value="">All Status</option>
        <option value="upcoming">Upcoming</option>
        <option value="active">Active</option>
        <option value="ended">Ended</option>
        <option value="cancelled">Cancelled</option>
      </select>
    </div>

    <div v-if="loading" class="loading">
      Loading games...
    </div>

    <div v-else-if="error" class="error">
      {{ error }}
    </div>

    <div v-else-if="games.length === 0" class="empty-state">
      <p>🎮 No games found</p>
      <p>Create a new betting game to get started!</p>
    </div>

    <div v-else class="games-grid">
      <div
        v-for="game in games"
        :key="game.bettingGameId"
        class="game-card"
      >
        <div class="card-header">
          <h3>{{ game.name }}</h3>
          <span :class="['status', game.status]">
            {{ game.status }}
          </span>
        </div>

        <div class="card-body">
          <p class="description">{{ game.description }}</p>

          <div class="meta-info">
            <div class="meta-item">
              <span class="label">Type:</span>
              <span>{{ game.gameType?.typeName || '-' }}</span>
            </div>
            <div class="meta-item">
              <span class="label">Start:</span>
              <span>{{ formatDate(game.startDate) }}</span>
            </div>
            <div class="meta-item">
              <span class="label">End:</span>
              <span>{{ formatDate(game.endDate) }}</span>
            </div>
            <div class="meta-item">
              <span class="label">Participants:</span>
              <span>{{ game.participantCount || 0 }}</span>
            </div>
            <div class="meta-item">
              <span class="label">Events:</span>
              <span>{{ game.eventCount || 0 }}</span>
            </div>
            <div v-if="game.baseFee" class="meta-item">
              <span class="label">Base Fee:</span>
              <span>€{{ game.baseFee }}</span>
            </div>
          </div>
        </div>

        <div class="card-actions" v-if="game.status === 'active'">
          <button
            @click="showEndGameModal(game)"
            class="btn-danger"
          >
            🛑 End Game
          </button>
        </div>
      </div>
    </div>

    <!-- Create Game Modal -->
    <div v-if="showCreateModal" class="modal-overlay" @click="closeCreateModal">
      <div class="modal modal-large" @click.stop>
        <h3>Create New Betting Game</h3>

        <form @submit.prevent="createGame">
          <div class="form-group">
            <label for="name">Name *</label>
            <input
              id="name"
              v-model="createForm.name"
              type="text"
              required
              placeholder="Game name"
            />
          </div>

          <div class="form-group">
            <label for="description">Description *</label>
            <textarea
              id="description"
              v-model="createForm.description"
              required
              placeholder="Game description"
              rows="3"
            ></textarea>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label for="gameTypeId">Game Type ID *</label>
              <input
                id="gameTypeId"
                v-model.number="createForm.gameTypeId"
                type="number"
                required
                placeholder="1"
              />
            </div>

            <div class="form-group">
              <label for="baseFee">Base Fee (€)</label>
              <input
                id="baseFee"
                v-model.number="createForm.baseFee"
                type="number"
                step="0.01"
                placeholder="0.00"
              />
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label for="startDate">Start Date *</label>
              <input
                id="startDate"
                v-model="createForm.startDate"
                type="datetime-local"
                required
              />
            </div>

            <div class="form-group">
              <label for="endDate">End Date *</label>
              <input
                id="endDate"
                v-model="createForm.endDate"
                type="datetime-local"
                required
              />
            </div>
          </div>

          <div v-if="createError" class="error-message">
            {{ createError }}
          </div>

          <div v-if="createSuccess" class="success-message">
            ✅ Game created successfully!
          </div>

          <div class="modal-actions">
            <button type="button" @click="closeCreateModal" class="btn-cancel">
              Cancel
            </button>
            <button
              type="submit"
              class="btn-primary"
              :disabled="creatingGame"
            >
              {{ creatingGame ? 'Creating...' : 'Create Game' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- End Game Modal -->
    <div v-if="endModal.show" class="modal-overlay" @click="closeEndModal">
      <div class="modal" @click.stop>
        <h3>End Game?</h3>
        <p>Are you sure you want to end "{{ endModal.game?.name }}"?</p>

        <div class="form-group">
          <label for="endReason">Reason *</label>
          <textarea
            id="endReason"
            v-model="endModal.reason"
            required
            placeholder="Reason for ending the game"
            rows="2"
          ></textarea>
        </div>

        <div class="form-group">
          <label class="checkbox-label">
            <input
              v-model="endModal.finalizeScores"
              type="checkbox"
            />
            <span>Finalize scores</span>
          </label>
        </div>

        <div v-if="endError" class="error-message">
          {{ endError }}
        </div>

        <div class="modal-actions">
          <button @click="closeEndModal" class="btn-cancel">
            Cancel
          </button>
          <button
            @click="confirmEndGame"
            class="btn-danger"
            :disabled="endingGame || !endModal.reason"
          >
            {{ endingGame ? 'Ending...' : 'End Game' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '@/services/api'

const games = ref([])
const loading = ref(false)
const error = ref(null)
const statusFilter = ref('')

const showCreateModal = ref(false)
const createForm = ref({
  name: '',
  description: '',
  gameTypeId: null,
  startDate: '',
  endDate: '',
  baseFee: null
})
const creatingGame = ref(false)
const createError = ref(null)
const createSuccess = ref(false)

const endModal = ref({
  show: false,
  game: null,
  reason: '',
  finalizeScores: true
})
const endingGame = ref(false)
const endError = ref(null)

const loadGames = async () => {
  loading.value = true
  error.value = null

  try {
    const params = {}
    if (statusFilter.value) {
      params.status = statusFilter.value
    }

    const response = await api.admin.getAllGames(params)
    games.value = response.data.games || []
  } catch (err) {
    error.value = 'Failed to load games. Please try again.'
    console.error('Error loading games:', err)
  } finally {
    loading.value = false
  }
}

const createGame = async () => {
  creatingGame.value = true
  createError.value = null
  createSuccess.value = false

  try {
    const gameData = {
      name: createForm.value.name,
      description: createForm.value.description,
      gameTypeId: createForm.value.gameTypeId,
      startDate: new Date(createForm.value.startDate).toISOString(),
      endDate: new Date(createForm.value.endDate).toISOString()
    }

    if (createForm.value.baseFee) {
      gameData.baseFee = createForm.value.baseFee
    }

    await api.admin.createGame(gameData)
    createSuccess.value = true

    setTimeout(() => {
      closeCreateModal()
      loadGames()
    }, 1500)
  } catch (err) {
    createError.value = err.response?.data?.message || 'Failed to create game'
    console.error('Error creating game:', err)
  } finally {
    creatingGame.value = false
  }
}

const closeCreateModal = () => {
  showCreateModal.value = false
  createForm.value = {
    name: '',
    description: '',
    gameTypeId: null,
    startDate: '',
    endDate: '',
    baseFee: null
  }
  createError.value = null
  createSuccess.value = false
}

const showEndGameModal = (game) => {
  endModal.value.show = true
  endModal.value.game = game
  endModal.value.reason = ''
  endModal.value.finalizeScores = true
  endError.value = null
}

const closeEndModal = () => {
  endModal.value.show = false
  endModal.value.game = null
}

const confirmEndGame = async () => {
  if (!endModal.value.game || !endModal.value.reason) return

  endingGame.value = true
  endError.value = null

  try {
    await api.admin.endGame(endModal.value.game.bettingGameId, {
      reason: endModal.value.reason,
      finalizeScores: endModal.value.finalizeScores
    })

    closeEndModal()
    loadGames()
  } catch (err) {
    endError.value = err.response?.data?.message || 'Failed to end game'
    console.error('Error ending game:', err)
  } finally {
    endingGame.value = false
  }
}

const formatDate = (dateString) => {
  return new Date(dateString).toLocaleString('de-DE', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  })
}

onMounted(() => {
  loadGames()
})
</script>

<style scoped>
.admin-games-view {
  padding: 2rem 0;
}

.header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 2rem;
}

h1 {
  color: #1f2937;
  font-size: 2rem;
  margin: 0;
}

.filters {
  margin-bottom: 2rem;
}

.filters select {
  padding: 0.5rem 1rem;
  border: 2px solid #e5e7eb;
  border-radius: 6px;
  font-size: 1rem;
  cursor: pointer;
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
  padding: 4rem;
  color: #666;
}

.empty-state p:first-child {
  font-size: 3rem;
  margin-bottom: 1rem;
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

.status {
  padding: 0.25rem 0.75rem;
  border-radius: 12px;
  font-size: 0.875rem;
  font-weight: 600;
  text-transform: uppercase;
}

.status.upcoming {
  background: #dbeafe;
  color: #1e40af;
}

.status.active {
  background: #d1fae5;
  color: #065f46;
}

.status.ended {
  background: #f3f4f6;
  color: #6b7280;
}

.status.cancelled {
  background: #fee2e2;
  color: #dc2626;
}

.card-body {
  padding: 1.25rem;
}

.description {
  color: #6b7280;
  font-size: 0.875rem;
  margin: 0 0 1rem 0;
}

.meta-info {
  margin-bottom: 0;
}

.meta-item {
  display: flex;
  justify-content: space-between;
  padding: 0.5rem 0;
  border-bottom: 1px solid #f3f4f6;
  font-size: 0.875rem;
}

.meta-item:last-child {
  border-bottom: none;
}

.meta-item .label {
  color: #6b7280;
  font-weight: 500;
}

.card-actions {
  padding: 1rem 1.25rem;
  background: #f9fafb;
  border-top: 1px solid #e5e7eb;
  display: flex;
  justify-content: flex-end;
}

.btn-primary {
  padding: 0.75rem 1.5rem;
  background: #2563eb;
  color: white;
  border: none;
  border-radius: 8px;
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

.modal-large {
  max-width: 600px;
}

.modal h3 {
  margin: 0 0 1.5rem 0;
  color: #1f2937;
}

.modal p {
  margin: 0.5rem 0;
  color: #6b7280;
}

.form-group {
  margin-bottom: 1.25rem;
}

.form-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
}

label {
  display: block;
  margin-bottom: 0.5rem;
  color: #374151;
  font-weight: 600;
  font-size: 0.875rem;
}

input[type="text"],
input[type="number"],
input[type="datetime-local"],
textarea {
  width: 100%;
  padding: 0.75rem;
  border: 2px solid #e5e7eb;
  border-radius: 6px;
  font-size: 1rem;
  transition: border-color 0.2s;
  box-sizing: border-box;
}

input:focus,
textarea:focus {
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

.modal-actions {
  display: flex;
  gap: 1rem;
  margin-top: 1.5rem;
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
</style>
