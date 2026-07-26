<template>
  <div class="predictions-view">
    <div class="header">
      <h1>My Predictions</h1>
      <router-link to="/predictions/new" class="btn-primary">
        ➕ New Prediction
      </router-link>
    </div>

    <div class="filters">
      <select v-model="statusFilter" @change="loadPredictions">
        <option value="">All Status</option>
        <option value="submitted">Submitted</option>
        <option value="pending">Pending</option>
        <option value="evaluated">Evaluated</option>
      </select>
    </div>

    <div v-if="loading" class="loading">
      Loading predictions...
    </div>

    <div v-else-if="error" class="error">
      {{ error }}
    </div>

    <div v-else-if="predictions.length === 0" class="empty-state">
      <p>📋 No predictions yet</p>
      <p>Start by creating your first prediction!</p>
    </div>

    <div v-else class="predictions-grid">
      <div
        v-for="prediction in predictions"
        :key="prediction.predictionId"
        class="prediction-card"
      >
        <div class="card-header">
          <h3>{{ prediction.eventName }}</h3>
          <span :class="['status', prediction.status]">
            {{ prediction.status }}
          </span>
        </div>

        <div class="card-body">
          <div class="prediction-data">
            <strong>Prediction:</strong>
            <pre>{{ formatPredictionData(prediction.predictionData) }}</pre>
          </div>

          <div class="meta-info">
            <div class="meta-item">
              <span class="label">Submitted:</span>
              <span>{{ formatDate(prediction.submittedAt) }}</span>
            </div>
            <div class="meta-item">
              <span class="label">Deadline:</span>
              <span>{{ formatDate(prediction.deadline) }}</span>
            </div>
            <div v-if="prediction.updatedAt" class="meta-item">
              <span class="label">Updated:</span>
              <span>{{ formatDate(prediction.updatedAt) }}</span>
            </div>
          </div>

          <div v-if="prediction.result" class="result">
            <strong>Result:</strong>
            <div class="result-info">
              <span>Points: {{ prediction.result.pointsEarned || 0 }}</span>
              <span v-if="prediction.result.prizeAmount">
                Prize: €{{ prediction.result.prizeAmount }}
              </span>
            </div>
          </div>
        </div>

        <div class="card-actions">
          <button
            v-if="prediction.isEditable"
            @click="editPrediction(prediction.predictionId)"
            class="btn-secondary"
          >
            ✏️ Edit
          </button>
          <span v-else class="locked">🔒 Locked</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import api from '@/services/api'

const router = useRouter()
const authStore = useAuthStore()

const predictions = ref([])
const loading = ref(false)
const error = ref(null)
const statusFilter = ref('')

const loadPredictions = async () => {
  loading.value = true
  error.value = null

  try {
    const params = {}
    if (statusFilter.value) {
      params.status = statusFilter.value
    }

    const response = await api.getPredictions(authStore.participantId, params)
    predictions.value = response.data.predictions || []
  } catch (err) {
    error.value = 'Failed to load predictions. Please try again.'
    console.error('Error loading predictions:', err)
  } finally {
    loading.value = false
  }
}

const editPrediction = (predictionId) => {
  router.push(`/predictions/${predictionId}/edit`)
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

const formatPredictionData = (data) => {
  return JSON.stringify(data, null, 2)
}

onMounted(() => {
  loadPredictions()
})
</script>

<style scoped>
.predictions-view {
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

.btn-primary {
  padding: 0.75rem 1.5rem;
  background: #2563eb;
  color: white;
  text-decoration: none;
  border-radius: 8px;
  font-weight: 600;
  transition: background 0.2s;
  display: inline-block;
}

.btn-primary:hover {
  background: #1d4ed8;
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

.predictions-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
  gap: 1.5rem;
}

.prediction-card {
  background: white;
  border-radius: 12px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.1);
  overflow: hidden;
  transition: transform 0.2s, box-shadow 0.2s;
}

.prediction-card:hover {
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

.status.submitted {
  background: #dbeafe;
  color: #1e40af;
}

.status.pending {
  background: #fef3c7;
  color: #92400e;
}

.status.evaluated {
  background: #d1fae5;
  color: #065f46;
}

.card-body {
  padding: 1.25rem;
}

.prediction-data {
  margin-bottom: 1rem;
}

.prediction-data strong {
  display: block;
  margin-bottom: 0.5rem;
  color: #374151;
}

.prediction-data pre {
  background: #f3f4f6;
  padding: 0.75rem;
  border-radius: 6px;
  font-size: 0.875rem;
  overflow-x: auto;
}

.meta-info {
  margin-bottom: 1rem;
}

.meta-item {
  display: flex;
  justify-content: space-between;
  padding: 0.5rem 0;
  border-bottom: 1px solid #f3f4f6;
  font-size: 0.875rem;
}

.meta-item .label {
  color: #6b7280;
  font-weight: 500;
}

.result {
  padding: 1rem;
  background: #f0fdf4;
  border-radius: 6px;
  margin-top: 1rem;
}

.result strong {
  display: block;
  margin-bottom: 0.5rem;
  color: #065f46;
}

.result-info {
  display: flex;
  gap: 1rem;
  color: #065f46;
  font-weight: 600;
}

.card-actions {
  padding: 1rem 1.25rem;
  background: #f9fafb;
  border-top: 1px solid #e5e7eb;
  display: flex;
  justify-content: flex-end;
}

.btn-secondary {
  padding: 0.5rem 1rem;
  background: white;
  color: #2563eb;
  border: 2px solid #2563eb;
  border-radius: 6px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s;
}

.btn-secondary:hover {
  background: #2563eb;
  color: white;
}

.locked {
  color: #9ca3af;
  font-size: 0.875rem;
  font-weight: 500;
}
</style>
