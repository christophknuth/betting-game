<template>
  <ParticipantScope>
    <div class="page-header">
      <div>
        <h2>Meine Tippreihe</h2>
        <p class="subtitle">
          Sechs Zahlen je Tippperiode. Ohne Angabe die heute laufende Periode.
        </p>
      </div>
    </div>

    <div class="card section">
      <div class="field-inline">
        <div class="field">
          <label for="betPeriodId">Tippperiode</label>
          <input
            id="betPeriodId"
            v-model="betPeriodId"
            type="number"
            min="1"
            placeholder="laufende Periode"
          >
          <span class="hint">ID der Periode, leer = heute laufend</span>
        </div>
        <button
          class="btn-primary"
          :disabled="betRow.loading"
          @click="reload"
        >
          Anzeigen
        </button>
      </div>
    </div>

    <div
      v-if="betRow.loading"
      class="state loading"
    >
      Wird geladen …
    </div>

    <!-- A 404 here is an answer, not a fault: it means no row is assigned for
         that period. So the API's message goes into the empty state. -->
    <div
      v-else-if="betRow.error"
      class="state empty"
    >
      {{ betRow.error }}
    </div>

    <div
      v-else-if="betRow.data"
      class="card"
    >
      <div class="numbers section">
        <span
          v-for="number in betRow.data.numbers"
          :key="number"
          class="ball"
        >{{ number }}</span>
      </div>

      <dl class="facts">
        <dt>Tippperiode</dt>
        <dd>{{ betRow.data.betPeriod?.name }} (#{{ betRow.data.betPeriod?.betPeriodId }})</dd>

        <dt>Zeitraum</dt>
        <dd>
          {{ formatDate(betRow.data.betPeriod?.startDate) }} –
          {{ formatDate(betRow.data.betPeriod?.endDate) }}
        </dd>

        <dt>Tippjahr</dt>
        <dd>{{ betRow.data.betPeriod?.tippYearName ?? '–' }}</dd>

        <dt>Zugeordnet am</dt>
        <dd>{{ formatDateTime(betRow.data.assignedAt) }}</dd>

        <dt>Änderbar ab</dt>
        <dd>
          {{ betRow.data.changeableFrom
            ? formatDate(betRow.data.changeableFrom)
            : 'keine weitere Periode geplant' }}
        </dd>

        <dt>Auf Tippscheinen</dt>
        <dd>{{ betRow.data.ticketCount }}×</dd>
      </dl>

      <p class="state note">
        Ändern kann die Reihe nur der Administrator. Regulär wechselt sie mit der nächsten
        Periode; eine Korrektur innerhalb der laufenden Periode verlangt einen Grund und
        steht danach in der Event-Historie.
      </p>
    </div>
  </ParticipantScope>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import ParticipantScope from '@/components/ParticipantScope.vue'
import api from '@/services/api'
import { useQuery } from '@/composables/useCommand'
import { useAuthStore } from '@/stores/auth'
import { formatDate, formatDateTime } from '@/support/format'

const authStore = useAuthStore()
const betRow = useQuery()

const betPeriodId = ref('')

const reload = () =>
  betRow.load(() => api.getBetRow(authStore.participantId, betPeriodId.value || null))

onMounted(() => {
  if (authStore.participantId) {
    reload()
  }
})
</script>

<style scoped>
.numbers .ball {
  width: 3rem;
  height: 3rem;
  font-size: 1.125rem;
}

.facts {
  margin-bottom: 1rem;
}
</style>
