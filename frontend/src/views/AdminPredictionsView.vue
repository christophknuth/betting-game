<template>
  <div class="admin-predictions-view">
    <h1>📋 Admin - Predictions</h1>

    <div class="filters">
      <div class="filter-group">
        <label for="bettingGameId">Game ID</label>
        <input
          id="bettingGameId"
          v-model.number="filters.bettingGameId"
          type="number"
          placeholder="All"
          @change="loadPredictions"
        />
      </div>

      <div class="filter-group">
        <label for="eventId">Event ID</label>
        <input
          id="eventId"
          v-model.number="filters.eventId"
          type="number"
          placeholder="All"
          @change="loadPredictions"
        />
      </div>

      <div class="filter-group">
        <label for="participantId">Participant ID</label>
        <input
          id="participantId"
          v-model.number="filters.participantId"
          type="number"
          placeholder="All"
          @change="loadPredictions"
        />
      </div>

      <button @click="loadPredictions" class="btn-filter">
        🔍 Filter
      </button>
    </div>

    <div v-if="loading" class="loading">
      Loading predictions...
    </div>

    <div v-else-if="error" class="error">
      {{ error }}
    </div>

    <div v-else-if="predictions.length === 0" class="empty-state">
      <p>📋 No predictions found</p>
      <p>Adjust your filters or wait for participants to submit predictions.</p>
    </div>

    <div v-else>
      <div class="results-info">
        <span>{{ predictions.length }} prediction(s) found</span>
        <span v-if="pagination.totalPages > 1">
          Page {{ pagination.currentPage }} of {{ pagination.totalPages }}
        </span>
      </div>

      <div class="predictions-table-wrapper">
        <table class="predictions-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Participant</th>
              <th>Event</th>
              <th>Prediction Data</th>
              <th>Status</th>
              <th>Submitted</th>
              <th>Updated</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="prediction in predictions" :key="prediction.predictionId">
              <td>{{ prediction.predictionId }}</td>
              <td>{{ prediction.participantId }}</td>
              <td>{{ prediction.eventName || prediction.eventId }}</td>
              <td>
                <pre class="prediction-data">{{ formatPredictionData(prediction.predictionData) }}</pre>
              </td>
              <td>
                <span :class="['status', prediction.status]">
                  {{ prediction.status }}
                </span>
              </td>
              <td>{{ formatDate(prediction.submittedAt) }}</td>
              <td>{{ prediction.updatedAt ? formatDate(prediction.updatedAt) : '-' }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="pagination.totalPages > 1" class="pagination">
        <button
          @click="goToPage(pagination.currentPage - 1)"
          :disabled="pagination.currentPage <= 1"
          class="btn-page"
        >
          ← Previous
        </button>
        <span class="page-info">
          Page {{ pagination.currentPage }} of {{ pagination.totalPages }}
        </span>
        <button
          @click="goToPage(pagination.currentPage + 1)"
          :disabled="pagination.currentPage >= pagination.totalPages"
          class="btn-page"
        >
          Next →
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '@/services/api'

const predictions = ref([])
const loading = ref(false)
const error = ref(null)

const filters = ref({
  bettingGameId: null,
  eventId: null,
  participantId: null
})

const pagination = ref({
  currentPage: 1,
  totalPages: 1,
  pageSize: 50
})

const loadPredictions = async () => {
  loading.value = true
  error.value = null

  try {
    const params = {
      page: pagination.value.currentPage,
      pageSize: pagination.value.pageSize
    }

    if (filters.value.bettingGameId) {
      params.bettingGameId = filters.value.bettingGameId
    }
    if (filters.value.eventId) {
      params.eventId = filters.value.eventId
    }
    if (filters.value.participantId) {
      params.participantId = filters.value.participantId
    }

    const response = await api.admin.getAllPredictions(params)
    predictions.value = response.data.predictions || []

    if (response.data.pagination) {
      pagination.value.currentPage = response.data.pagination.currentPage || 1
      pagination.value.totalPages = response.data.pagination.totalPages || 1
    }
  } catch (err) {
    error.value = 'Failed to load predictions. Please try again.'
    console.error('Error loading predictions:', err)
  } finally {
    loading.value = false
  }
}

const goToPage = (page) => {
  pagination.value.currentPage = page
  loadPredictions()
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
.admin-predictions-view {
  padding: 2rem 0;
}

h1 {
  color: #1f2937;
  font-size: 2rem;
  margin-bottom: 2rem;
}

.filters {
  display: flex;
  gap: 1rem;
  align-items: flex-end;
  margin-bottom: 2rem;
  flex-wrap: wrap;
}

.filter-group {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.filter-group label {
  font-size: 0.75rem;
  color: #6b7280;
  font-weight: 600;
  text-transform: uppercase;
}

.filter-group input {
  padding: 0.5rem 0.75rem;
  border: 2px solid #e5e7eb;
  border-radius: 6px;
  font-size: 0.875rem;
  width: 120px;
}

.filter-group input:focus {
  outline: none;
  border-color: #2563eb;
}

.btn-filter {
  padding: 0.5rem 1rem;
  background: #2563eb;
  color: white;
  border: none;
  border-radius: 6px;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.2s;
}

.btn-filter:hover {
  background: #1d4ed8;
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

.results-info {
  display: flex;
  justify-content: space-between;
  margin-bottom: 1rem;
  color: #6b7280;
  font-size: 0.875rem;
}

.predictions-table-wrapper {
  overflow-x: auto;
  background: white;
  border-radius: 12px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.predictions-table {
  width: 100%;
  border-collapse: collapse;
}

.predictions-table th {
  background: #f9fafb;
  padding: 1rem;
  text-align: left;
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
  color: #6b7280;
  border-bottom: 2px solid #e5e7eb;
}

.predictions-table td {
  padding: 1rem;
  border-bottom: 1px solid #f3f4f6;
  font-size: 0.875rem;
  color: #374151;
}

.predictions-table tbody tr:hover {
  background: #f9fafb;
}

.prediction-data {
  background: #f3f4f6;
  padding: 0.5rem;
  border-radius: 4px;
  font-size: 0.75rem;
  max-width: 200px;
  overflow-x: auto;
  margin: 0;
}

.status {
  padding: 0.25rem 0.75rem;
  border-radius: 12px;
  font-size: 0.75rem;
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

.pagination {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 1rem;
  margin-top: 2rem;
}

.btn-page {
  padding: 0.5rem 1rem;
  background: white;
  color: #2563eb;
  border: 2px solid #2563eb;
  border-radius: 6px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s;
}

.btn-page:hover:not(:disabled) {
  background: #2563eb;
  color: white;
}

.btn-page:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.page-info {
  color: #6b7280;
  font-size: 0.875rem;
}
</style>
