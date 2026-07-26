<template>
  <div class="edit-prediction-view">
    <div class="header">
      <h1>Edit Prediction</h1>
      <router-link to="/predictions" class="btn-back">← Back</router-link>
    </div>

    <div v-if="loading" class="loading">
      Loading prediction...
    </div>

    <div v-else-if="loadError" class="error">
      {{ loadError }}
    </div>

    <div v-else-if="prediction" class="form-card">
      <div class="info-section">
        <h3>{{ prediction.eventName }}</h3>
        <div class="info-grid">
          <div>
            <strong>Status:</strong>
            <span :class="['status', prediction.status]">
              {{ prediction.status }}
            </span>
          </div>
          <div>
            <strong>Deadline:</strong>
            {{ formatDate(prediction.deadline) }}
          </div>
        </div>
      </div>

      <div v-if="!prediction.isEditable" class="warning-box">
        ⚠️ This prediction cannot be edited anymore. The deadline has passed or it has been evaluated.
      </div>

      <form v-else @submit.prevent="updatePrediction">
        <div class="form-group">
          <label>Current Prediction Data</label>
          <pre class="current-data">{{ formatJson(prediction.predictionData) }}</pre>
        </div>

        <div class="form-group">
          <label>Updated Prediction Data *</label>
          <p class="help-text">Modify your prediction below</p>
          
          <div class="json-editor">
            <textarea
              v-model="predictionDataJson"
              rows="8"
              @input="validateJson"
            ></textarea>
          </div>
          
          <div v-if="jsonError" class="json-error">
            ❌ Invalid JSON: {{ jsonError }}
          </div>
          <div v-else-if="predictionDataJson && hasChanges" class="json-valid">
            ✅ Valid JSON - Changes detected
          </div>
        </div>

        <div v-if="error" class="error-message">
          {{ error }}
        </div>

        <div class="form-actions">
          <button
            type="button"
            @click="$router.back()"
            class="btn-cancel"
          >
            Cancel
          </button>
          <button
            type="submit"
            class="btn-submit"
            :disabled="submitting || !!jsonError || !hasChanges"
          >
            {{ submitting ? 'Updating...' : 'Update Prediction' }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import api from '@/services/api'

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()

const prediction = ref(null)
const predictionDataJson = ref('')
const jsonError = ref(null)
const loading = ref(false)
const loadError = ref(null)
const submitting = ref(false)
const error = ref(null)

const predictionData = computed(() => {
  try {
    return JSON.parse(predictionDataJson.value)
  } catch {
    return null
  }
})

const hasChanges = computed(() => {
  if (!prediction.value || !predictionData.value) return false
  return JSON.stringify(prediction.value.predictionData) !== JSON.stringify(predictionData.value)
})

const validateJson = () => {
  jsonError.value = null
  if (!predictionDataJson.value) return

  try {
    JSON.parse(predictionDataJson.value)
  } catch (e) {
    jsonError.value = e.message
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

const formatJson = (data) => {
  return JSON.stringify(data, null, 2)
}

const loadPrediction = async () => {
  loading.value = true
  loadError.value = null

  try {
    const predictionId = route.params.id
    const response = await api.getPrediction(authStore.participantId, predictionId)
    prediction.value = response.data
    predictionDataJson.value = formatJson(response.data.predictionData)
  } catch (err) {
    loadError.value = 'Failed to load prediction'
    console.error('Error loading prediction:', err)
  } finally {
    loading.value = false
  }
}

const updatePrediction = async () => {
  if (!hasChanges.value) {
    error.value = 'No changes detected'
    return
  }

  if (!predictionData.value) {
    error.value = 'Please enter valid prediction data'
    return
  }

  submitting.value = true
  error.value = null

  try {
    await api.updatePrediction(
      authStore.participantId,
      prediction.value.predictionId,
      predictionData.value
    )

    router.push('/predictions')
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to update prediction'
    console.error('Error updating prediction:', err)
  } finally {
    submitting.value = false
  }
}

onMounted(() => {
  loadPrediction()
})
</script>

<style scoped>
.edit-prediction-view {
  padding: 2rem 0;
  max-width: 800px;
  margin: 0 auto;
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

.btn-back {
  padding: 0.5rem 1rem;
  color: #6b7280;
  text-decoration: none;
  border: 2px solid #e5e7eb;
  border-radius: 6px;
  font-weight: 500;
  transition: all 0.2s;
}

.btn-back:hover {
  background: #f3f4f6;
  border-color: #d1d5db;
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

.form-card {
  background: white;
  border-radius: 12px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.1);
  padding: 2rem;
}

.info-section {
  padding-bottom: 1.5rem;
  margin-bottom: 1.5rem;
  border-bottom: 2px solid #e5e7eb;
}

.info-section h3 {
  color: #1f2937;
  margin-bottom: 1rem;
}

.info-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 1rem;
}

.info-grid div {
  font-size: 0.875rem;
}

.info-grid strong {
  display: block;
  color: #6b7280;
  margin-bottom: 0.25rem;
}

.status {
  display: inline-block;
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

.warning-box {
  padding: 1rem;
  background: #fef3c7;
  border: 1px solid #fbbf24;
  border-radius: 6px;
  color: #92400e;
  margin-bottom: 1.5rem;
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

.current-data {
  background: #f3f4f6;
  padding: 1rem;
  border-radius: 6px;
  font-size: 0.875rem;
  overflow-x: auto;
  border: 1px solid #e5e7eb;
}

textarea {
  width: 100%;
  padding: 0.75rem;
  border: 2px solid #e5e7eb;
  border-radius: 6px;
  font-size: 1rem;
  font-family: 'Courier New', monospace;
  background: #f9fafb;
  transition: border-color 0.2s;
}

textarea:focus {
  outline: none;
  border-color: #2563eb;
}

.help-text {
  display: block;
  margin-top: 0.25rem;
  color: #6b7280;
  font-size: 0.875rem;
}

.json-error {
  margin-top: 0.5rem;
  padding: 0.75rem;
  background: #fee2e2;
  border: 1px solid #fca5a5;
  border-radius: 6px;
  color: #dc2626;
  font-size: 0.875rem;
}

.json-valid {
  margin-top: 0.5rem;
  padding: 0.75rem;
  background: #d1fae5;
  border: 1px solid #6ee7b7;
  border-radius: 6px;
  color: #065f46;
  font-size: 0.875rem;
}

.error-message {
  margin-bottom: 1rem;
  padding: 1rem;
  background: #fee2e2;
  border: 1px solid #fca5a5;
  border-radius: 6px;
  color: #dc2626;
}

.form-actions {
  display: flex;
  gap: 1rem;
  justify-content: flex-end;
  margin-top: 2rem;
  padding-top: 1.5rem;
  border-top: 1px solid #e5e7eb;
}

.btn-cancel {
  padding: 0.75rem 1.5rem;
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

.btn-submit {
  padding: 0.75rem 1.5rem;
  background: #2563eb;
  color: white;
  border: none;
  border-radius: 6px;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.2s;
}

.btn-submit:hover:not(:disabled) {
  background: #1d4ed8;
}

.btn-submit:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
</style>
