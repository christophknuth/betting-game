<template>
  <ParticipantScope>
    <div class="page-header">
      <div>
        <h1>Meine Tippreihe</h1>
        <p class="subtitle">
          Die sechs Zahlen der heute laufenden Tippperiode.
        </p>
      </div>
      <button
        class="btn-secondary"
        :disabled="betRow.loading"
        @click="reload"
      >
        Aktualisieren
      </button>
    </div>

    <div
      v-if="betRow.loading"
      class="state loading"
    >
      Wird geladen …
    </div>

    <!-- A 404 here is an answer, not a fault: it means no row is assigned for
         the running period. Anything else went wrong and has to look like it. -->
    <div
      v-else-if="betRow.isEmpty()"
      class="state empty"
    >
      {{ betRow.error }}
    </div>
    <div
      v-else-if="betRow.error"
      class="state error"
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
        Ändern kann die Reihe nur der Administrator; regulär wechselt sie mit der nächsten
        Periode.
      </p>
    </div>
  </ParticipantScope>
</template>

<script setup>
import { onMounted } from 'vue'
import ParticipantScope from '@/components/ParticipantScope.vue'
import api from '@/services/api'
import { useQuery } from '@/composables/useCommand'
import { useAuthStore } from '@/stores/auth'
import { formatDate, formatDateTime } from '@/support/format'

const authStore = useAuthStore()
const betRow = useQuery()

/**
 * Always the running period.
 *
 * There used to be a field for a bet period id here, so that an earlier period
 * could be looked at. Nothing could fill it: no participant-facing endpoint
 * lists bet periods, so the only way to a number was guessing. Showing the
 * running row - which is what B-01 asks for - and leaving period history to a
 * story that comes with a list is the honest half of that.
 */
const reload = () => betRow.load(() => api.getBetRow(authStore.participantId))

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
