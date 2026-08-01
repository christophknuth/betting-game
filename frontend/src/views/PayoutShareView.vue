<template>
  <ParticipantScope>
    <div class="page-header">
      <div>
        <h2>Mein Gewinnanteil</h2>
        <p class="subtitle">
          Die Jahresgewinne werden am Jahresende gleichmäßig auf alle Teilnehmer verteilt.
        </p>
      </div>
    </div>

    <div class="card section">
      <div class="field-inline">
        <div class="field">
          <label for="tippYearId">Tippjahr</label>
          <input
            id="tippYearId"
            v-model="tippYearId"
            type="number"
            min="1"
            placeholder="laufendes Jahr"
          >
        </div>
        <button
          class="btn-primary"
          :disabled="query.loading"
          @click="reload"
        >
          Anzeigen
        </button>
      </div>
    </div>

    <div
      v-if="query.loading"
      class="state loading"
    >
      Wird geladen …
    </div>
    <div
      v-else-if="query.error"
      class="state empty"
    >
      {{ query.error }}
    </div>

    <template v-else-if="query.data">
      <div class="card section">
        <div class="page-header">
          <div>
            <h3>{{ query.data.tippYearName }}</h3>
            <p class="subtitle">
              Tippjahr #{{ query.data.tippYearId }}
            </p>
          </div>
          <span
            class="badge"
            :class="query.data.tippYearStatus"
          >
            {{ statusLabel(query.data.tippYearStatus) }}
          </span>
        </div>

        <dl class="facts">
          <dt>Gewinnsumme des Tippscheins</dt>
          <dd>{{ formatAmount(query.data.totalWinnings) }}</dd>

          <dt>Teilnehmer</dt>
          <dd>{{ query.data.participantCount ?? '–' }}</dd>

          <template v-if="distributed">
            <dt>Ausgeschüttet am</dt>
            <dd>{{ formatDateTime(query.data.distributedAt) }}</dd>

            <dt>Zahlungsstatus</dt>
            <dd>
              <span
                class="badge"
                :class="query.data.paymentStatus"
              >
                {{ statusLabel(query.data.paymentStatus) }}
              </span>
            </dd>
          </template>
        </dl>
      </div>

      <div class="card">
        <h3>{{ distributed ? 'Mein Anteil' : 'Zwischenstand' }}</h3>
        <p
          class="figure"
          :class="{ provisional: !distributed }"
        >
          {{ formatAmount(distributed ? query.data.amount : query.data.provisionalAmount) }}
        </p>

        <p
          v-if="!distributed"
          class="state note"
        >
          Solange die Ausschüttung nicht gebucht ist, ist das nur ein Zwischenstand — er
          ändert sich mit jeder weiteren Ziehung.
        </p>
      </div>
    </template>
  </ParticipantScope>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import ParticipantScope from '@/components/ParticipantScope.vue'
import api from '@/services/api'
import { useQuery } from '@/composables/useCommand'
import { useAuthStore } from '@/stores/auth'
import { formatAmount, formatDateTime, statusLabel } from '@/support/format'

const authStore = useAuthStore()
const query = useQuery()

const tippYearId = ref('')

// `amount` stays null until the payout is booked - that null is the signal, not
// a missing value, so it decides which of the two figures is shown.
const distributed = computed(() => query.data?.amount !== null && query.data?.amount !== undefined)

const reload = () =>
  query.load(() => api.getPayoutShare(authStore.participantId, tippYearId.value || null))

onMounted(() => {
  if (authStore.participantId) {
    reload()
  }
})
</script>

<style scoped>
.figure {
  font-size: 2.25rem;
  font-weight: 700;
  color: var(--green);
  font-variant-numeric: tabular-nums;
  margin-bottom: 1rem;
}

.figure.provisional {
  color: var(--gray-600);
}
</style>
