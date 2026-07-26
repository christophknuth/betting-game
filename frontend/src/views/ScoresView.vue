<template>
  <div class="scores-view">
    <h1>My Scores</h1>

    <div v-if="loading" class="loading">
      Loading scores...
    </div>

    <div v-else-if="error" class="error">
      {{ error }}
    </div>

    <div v-else>
      <!-- Summary Card -->
      <div class="summary-card">
        <h2>📊 Summary</h2>
        <div class="summary-grid">
          <div class="summary-item">
            <div class="value">{{ summary.totalPoints || 0 }}</div>
            <div class="label">Total Points</div>
          </div>
          <div class="summary-item">
            <div class="value">€{{ (summary.totalPrizeAmount || 0).toFixed(2) }}</div>
            <div class="label">Total Prize Money</div>
          </div>
          <div class="summary-item">
            <div class="value">{{ summary.gamesParticipated || 0 }}</div>
            <div class="label">Games Participated</div>
          </div>
        </div>
      </div>

      <!-- Scores List -->
      <div v-if="scores.length === 0" class="empty-state">
        <p>🏆 No scores yet</p>
        <p>Your scores will appear here once predictions are evaluated.</p>
      </div>

      <div v-else class="scores-list">
        <h2>Score History</h2>
        <div class="score-cards">
          <div
            v-for="score in scores"
            :key="score.scoreId"
            class="score-card"
          >
            <div class="card-header">
              <div>
                <h3>{{ score.eventName }}</h3>
                <p class="game-name">{{ score.bettingGameName }}</p>
              </div>
              <div class="points">
                {{ score.pointsEarned || 0 }} pts
              </div>
            </div>

            <div class="card-body">
              <div v-if="score.prizeAmount" class="prize">
                💰 Prize: €{{ score.prizeAmount.toFixed(2) }}
              </div>
              <div class="date">
                📅 {{ formatDate(score.calculatedAt) }}
              </div>
              <div v-if="score.rank" class="rank">
                🏅 Rank: #{{ score.rank }}
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import api from '@/services/api'

const authStore = useAuthStore()

const scores = ref([])
const summary = ref({})
const loading = ref(false)
const error = ref(null)

const loadScores = async () => {
  loading.value = true
  error.value = null

  try {
    const response = await api.getScores(authStore.participantId)
    scores.value = response.data.scores || []
    summary.value = response.data.summary || {}
  } catch (err) {
    error.value = 'Failed to load scores. Please try again.'
    console.error('Error loading scores:', err)
  } finally {
    loading.value = false
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
  loadScores()
})
</script>

<style scoped>
.scores-view {
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

.loading,
.error {
  text-align: center;
  padding: 3rem;
  color: #666;
}

.error {
  color: #dc2626;
}

.summary-card {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: white;
  padding: 2rem;
  border-radius: 16px;
  margin-bottom: 3rem;
  box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
}

.summary-card h2 {
  color: white;
  margin-bottom: 1.5rem;
}

.summary-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 2rem;
}

.summary-item {
  text-align: center;
}

.summary-item .value {
  font-size: 2.5rem;
  font-weight: bold;
  margin-bottom: 0.5rem;
}

.summary-item .label {
  font-size: 0.875rem;
  opacity: 0.9;
  text-transform: uppercase;
  letter-spacing: 0.5px;
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

.scores-list {
  margin-top: 2rem;
}

.score-cards {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
  gap: 1.5rem;
}

.score-card {
  background: white;
  border-radius: 12px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.1);
  overflow: hidden;
  transition: transform 0.2s, box-shadow 0.2s;
}

.score-card:hover {
  transform: translateY(-4px);
  box-shadow: 0 4px 16px rgba(0,0,0,0.15);
}

.card-header {
  padding: 1.25rem;
  background: #f9fafb;
  border-bottom: 1px solid #e5e7eb;
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
}

.card-header h3 {
  margin: 0 0 0.25rem 0;
  color: #1f2937;
  font-size: 1.125rem;
}

.game-name {
  margin: 0;
  color: #6b7280;
  font-size: 0.875rem;
}

.points {
  padding: 0.5rem 1rem;
  background: #dbeafe;
  color: #1e40af;
  border-radius: 20px;
  font-weight: 700;
  font-size: 1.125rem;
}

.card-body {
  padding: 1.25rem;
}

.card-body > div {
  margin-bottom: 0.75rem;
}

.card-body > div:last-child {
  margin-bottom: 0;
}

.prize {
  color: #059669;
  font-weight: 600;
  font-size: 1.125rem;
}

.date {
  color: #6b7280;
  font-size: 0.875rem;
}

.rank {
  color: #f59e0b;
  font-weight: 600;
}
</style>
