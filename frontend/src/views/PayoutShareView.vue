<template>
  <ParticipantScope>
    <div class="page-header">
      <div>
        <h1>Mein Gewinnanteil</h1>
        <p class="subtitle">
          Die Jahresgewinne werden am Jahresende gleichmäßig auf alle Teilnehmer verteilt.
        </p>
      </div>
    </div>

    <div class="card section">
      <div class="field-inline">
        <TippYearPicker
          v-model="tippYearId"
          empty-label="laufendes Jahr"
        />
      </div>
    </div>

    <div
      v-if="query.loading"
      class="state loading"
    >
      Wird geladen …
    </div>
    <!-- No payout share for this year is an answer; a failing API is not. -->
    <div
      v-else-if="query.isEmpty()"
      class="state empty"
    >
      {{ query.error }}
    </div>
    <div
      v-else-if="query.error"
      class="state error"
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
import { computed, onMounted, ref, watch } from 'vue'
import ParticipantScope from '@/components/ParticipantScope.vue'
import TippYearPicker from '@/components/TippYearPicker.vue'
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

// Choosing a year is the request - there was nothing an "Anzeigen" button
// added except a second click on a decision already made.
watch(tippYearId, reload)

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
