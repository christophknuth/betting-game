<template>
  <div class="admin-results-view">
    <h1>📊 Admin - Results</h1>

    <div class="sections">
      <!-- Record Result -->
      <section class="section">
        <h2>🏆 Record Event Result</h2>

        <div class="form-card">
          <form @submit.prevent="recordResult">
            <div class="form-row">
              <div class="form-group">
                <label for="eventId">Event ID *</label>
                <input
                  id="eventId"
                  v-model.number="resultForm.eventId"
                  type="number"
                  required
                  placeholder="Enter event ID"
                />
              </div>

              <div class="form-group">
                <label for="source">Source</label>
                <input
                  id="source"
                  v-model="resultForm.source"
                  type="text"
                  placeholder="e.g. official, manual"
                />
              </div>
            </div>

            <div class="form-group">
              <label for="resultData">Result Data (JSON) *</label>
              <textarea
                id="resultData"
                v-model="resultForm.resultDataJson"
                required
                placeholder='{"homeScore": 3, "awayScore": 2}'
                rows="4"
              ></textarea>
              <p v-if="jsonError" class="field-error">{{ jsonError }}</p>
            </div>

            <button
              type="submit"
              class="btn-primary"
              :disabled="recordingResult"
            >
              {{ recordingResult ? 'Recording...' : 'Record Result' }}
            </button>

            <div v-if="recordError" class="error-message">
              {{ recordError }}
            </div>

            <div v-if="recordSuccess" class="success-message">
              ✅ Result recorded successfully!
            </div>
          </form>
        </div>
      </section>

      <!-- Calculate Scores -->
      <section class="section">
        <h2>🧮 Calculate Scores</h2>

        <div class="form-card">
          <form @submit.prevent="calculateScores">
            <div class="form-group">
              <label for="calcEventId">Event ID *</label>
              <input
                id="calcEventId"
                v-model.number="calcForm.eventId"
                type="number"
                required
                placeholder="Enter event ID"
              />
            </div>

            <button
              type="submit"
              class="btn-primary"
              :disabled="calculatingScores"
            >
              {{ calculatingScores ? 'Calculating...' : 'Calculate Scores' }}
            </button>

            <div v-if="calcError" class="error-message">
              {{ calcError }}
            </div>

            <div v-if="calcSuccess" class="success-message">
              ✅ Score calculation initiated!
            </div>
          </form>
        </div>
      </section>

      <!-- Award Score Manually -->
      <section class="section">
        <h2>🎁 Award Score Manually</h2>

        <div class="form-card">
          <form @submit.prevent="awardScore">
            <div class="form-row">
              <div class="form-group">
                <label for="awardParticipantId">Participant ID *</label>
                <input
                  id="awardParticipantId"
                  v-model.number="awardForm.participantId"
                  type="number"
                  required
                  placeholder="Participant ID"
                />
              </div>

              <div class="form-group">
                <label for="awardBettingGameId">Game ID *</label>
                <input
                  id="awardBettingGameId"
                  v-model.number="awardForm.bettingGameId"
                  type="number"
                  required
                  placeholder="Game ID"
                />
              </div>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label for="awardEventId">Event ID *</label>
                <input
                  id="awardEventId"
                  v-model.number="awardForm.eventId"
                  type="number"
                  required
                  placeholder="Event ID"
                />
              </div>

              <div class="form-group">
                <label for="pointsEarned">Points</label>
                <input
                  id="pointsEarned"
                  v-model.number="awardForm.pointsEarned"
                  type="number"
                  placeholder="0"
                />
              </div>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label for="prizeAmount">Prize Amount (€)</label>
                <input
                  id="prizeAmount"
                  v-model.number="awardForm.prizeAmount"
                  type="number"
                  step="0.01"
                  placeholder="0.00"
                />
              </div>

              <div class="form-group">
                <label for="awardReason">Reason</label>
                <input
                  id="awardReason"
                  v-model="awardForm.reason"
                  type="text"
                  placeholder="Reason for award"
                />
              </div>
            </div>

            <button
              type="submit"
              class="btn-primary"
              :disabled="awardingScore"
            >
              {{ awardingScore ? 'Awarding...' : 'Award Score' }}
            </button>

            <div v-if="awardError" class="error-message">
              {{ awardError }}
            </div>

            <div v-if="awardSuccess" class="success-message">
              ✅ Score awarded successfully!
            </div>
          </form>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import api from '@/services/api'

const resultForm = ref({
  eventId: null,
  resultDataJson: '',
  source: ''
})
const recordingResult = ref(false)
const recordError = ref(null)
const recordSuccess = ref(false)
const jsonError = ref(null)

const calcForm = ref({
  eventId: null
})
const calculatingScores = ref(false)
const calcError = ref(null)
const calcSuccess = ref(false)

const awardForm = ref({
  participantId: null,
  bettingGameId: null,
  eventId: null,
  pointsEarned: null,
  prizeAmount: null,
  reason: ''
})
const awardingScore = ref(false)
const awardError = ref(null)
const awardSuccess = ref(false)

const recordResult = async () => {
  recordingResult.value = true
  recordError.value = null
  recordSuccess.value = false
  jsonError.value = null

  let resultData
  try {
    resultData = JSON.parse(resultForm.value.resultDataJson)
  } catch {
    jsonError.value = 'Invalid JSON format'
    recordingResult.value = false
    return
  }

  try {
    const payload = { resultData }
    if (resultForm.value.source) {
      payload.source = resultForm.value.source
    }

    await api.admin.recordResult(resultForm.value.eventId, payload)
    recordSuccess.value = true

    resultForm.value = { eventId: null, resultDataJson: '', source: '' }

    setTimeout(() => { recordSuccess.value = false }, 3000)
  } catch (err) {
    recordError.value = err.response?.data?.message || 'Failed to record result'
    console.error('Error recording result:', err)
  } finally {
    recordingResult.value = false
  }
}

const calculateScores = async () => {
  calculatingScores.value = true
  calcError.value = null
  calcSuccess.value = false

  try {
    await api.admin.calculateScores(calcForm.value.eventId)
    calcSuccess.value = true

    calcForm.value.eventId = null

    setTimeout(() => { calcSuccess.value = false }, 3000)
  } catch (err) {
    calcError.value = err.response?.data?.message || 'Failed to calculate scores'
    console.error('Error calculating scores:', err)
  } finally {
    calculatingScores.value = false
  }
}

const awardScore = async () => {
  awardingScore.value = true
  awardError.value = null
  awardSuccess.value = false

  try {
    const payload = {
      bettingGameId: awardForm.value.bettingGameId,
      eventId: awardForm.value.eventId
    }

    if (awardForm.value.pointsEarned != null) {
      payload.pointsEarned = awardForm.value.pointsEarned
    }
    if (awardForm.value.prizeAmount != null) {
      payload.prizeAmount = awardForm.value.prizeAmount
    }
    if (awardForm.value.reason) {
      payload.reason = awardForm.value.reason
    }

    await api.admin.awardScore(awardForm.value.participantId, payload)
    awardSuccess.value = true

    awardForm.value = {
      participantId: null,
      bettingGameId: null,
      eventId: null,
      pointsEarned: null,
      prizeAmount: null,
      reason: ''
    }

    setTimeout(() => { awardSuccess.value = false }, 3000)
  } catch (err) {
    awardError.value = err.response?.data?.message || 'Failed to award score'
    console.error('Error awarding score:', err)
  } finally {
    awardingScore.value = false
  }
}
</script>

<style scoped>
.admin-results-view {
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

.form-card {
  background: white;
  padding: 2rem;
  border-radius: 12px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.1);
  max-width: 600px;
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

textarea {
  font-family: monospace;
}

.field-error {
  color: #dc2626;
  font-size: 0.75rem;
  margin: 0.25rem 0 0 0;
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
