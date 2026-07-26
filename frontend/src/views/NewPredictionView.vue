<template>
  <div class="new-prediction-view">
    <div class="header">
      <h1>New Prediction</h1>
      <router-link to="/predictions" class="btn-back">← Back</router-link>
    </div>

    <div class="form-card">
      <form @submit.prevent="submitPrediction">
        <div class="form-group">
          <label for="eventId">Event ID *</label>
          <input
            id="eventId"
            v-model.number="form.eventId"
            type="number"
            required
            placeholder="Enter event ID"
          />
          <small>The ID of the event you want to predict</small>
        </div>

        <div class="form-group">
          <label>Prediction Data *</label>
          <p class="help-text">Enter your prediction as JSON</p>
          
          <div class="json-editor">
            <textarea
              v-model="predictionDataJson"
              rows="8"
              placeholder='{"homeScore": 2, "awayScore": 1}'
              @input="validateJson"
            ></textarea>
          </div>
          
          <div v-if="jsonError" class="json-error">
            ❌ Invalid JSON: {{ jsonError }}
          </div>
          <div v-else-if="predictionDataJson" class="json-valid">
            ✅ Valid JSON
          </div>
        </div>

        <div class="quick-templates">
          <label>Quick Templates:</label>
          <div class="template-buttons">
            <button
              type="button"
              @click="useTemplate('football')"
              class="btn-template"
            >
              ⚽ Football Score
            </button>
            <button
              type="button"
              @click="useTemplate('basketball')"
              class="btn-template"
            >
              🏀 Basketball Score
            </button>
            <button
              type="button"
              @click="useTemplate('winner')"
              class="btn-template"
            >
              🏆 Winner
            </button>
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
            :disabled="loading || !!jsonError || !predictionDataJson"
          >
            {{ loading ? 'Submitting...' : 'Submit Prediction' }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import api from '@/services/api'

const router = useRouter()
const authStore = useAuthStore()

const form = ref({
  eventId: null
})

const predictionDataJson = ref('')
const jsonError = ref(null)
const loading = ref(false)
const error = ref(null)

const predictionData = computed(() => {
  try {
    return JSON.parse(predictionDataJson.value)
  } catch {
    return null
  }
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

const useTemplate = (type) => {
  const templates = {
    football: {
      homeScore: 2,
      awayScore: 1
    },
    basketball: {
      team1Score: 95,
      team2Score: 88
    },
    winner: {
      winnerId: 1,
      winnerName: "Team A"
    }
  }

  predictionDataJson.value = JSON.stringify(templates[type], null, 2)
  validateJson()
}

const submitPrediction = async () => {
  if (!form.value.eventId) {
    error.value = 'Please enter an event ID'
    return
  }

  if (!predictionData.value) {
    error.value = 'Please enter valid prediction data'
    return
  }

  loading.value = true
  error.value = null

  try {
    await api.submitPrediction(
      authStore.participantId,
      form.value.eventId,
      predictionData.value
    )

    router.push('/predictions')
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to submit prediction'
    console.error('Error submitting prediction:', err)
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.new-prediction-view {
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

.form-card {
  background: white;
  border-radius: 12px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.1);
  padding: 2rem;
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

input,
textarea {
  width: 100%;
  padding: 0.75rem;
  border: 2px solid #e5e7eb;
  border-radius: 6px;
  font-size: 1rem;
  font-family: inherit;
  transition: border-color 0.2s;
}

input:focus,
textarea:focus {
  outline: none;
  border-color: #2563eb;
}

small,
.help-text {
  display: block;
  margin-top: 0.25rem;
  color: #6b7280;
  font-size: 0.875rem;
}

.json-editor textarea {
  font-family: 'Courier New', monospace;
  background: #f9fafb;
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

.quick-templates {
  margin-bottom: 1.5rem;
  padding: 1rem;
  background: #f9fafb;
  border-radius: 6px;
}

.template-buttons {
  display: flex;
  gap: 0.5rem;
  margin-top: 0.5rem;
  flex-wrap: wrap;
}

.btn-template {
  padding: 0.5rem 1rem;
  background: white;
  color: #374151;
  border: 2px solid #e5e7eb;
  border-radius: 6px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.2s;
}

.btn-template:hover {
  border-color: #2563eb;
  color: #2563eb;
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
