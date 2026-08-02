<template>
  <ParticipantScope>
    <div class="page-header">
      <div>
        <h1>Meine Gebühren</h1>
        <p class="subtitle">
          Der eigene Anteil an jedem Tippschein, mit Zahlungsstatus.
        </p>
      </div>
    </div>

    <div class="card section">
      <div class="field-inline">
        <TippYearPicker
          v-model="filters.tippYearId"
          empty-label="alle"
        />
        <div class="field">
          <label for="paymentStatus">Zahlungsstatus</label>
          <select
            id="paymentStatus"
            v-model="filters.paymentStatus"
          >
            <option value="">
              alle
            </option>
            <option value="open">
              offen
            </option>
            <option value="paid">
              bezahlt
            </option>
            <option value="waived">
              erlassen
            </option>
          </select>
        </div>
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
      class="state error"
    >
      {{ query.error }}
    </div>

    <template v-else-if="query.data">
      <div class="card-grid section">
        <div class="card">
          <h3>Belastet</h3>
          <p class="figure">
            {{ formatAmount(query.data.summary.totalCharged) }}
          </p>
        </div>
        <div class="card">
          <h3>Davon offen</h3>
          <p class="figure open">
            {{ formatAmount(query.data.summary.totalOpen) }}
          </p>
        </div>
        <div class="card">
          <h3>Offene Posten</h3>
          <p class="figure">
            {{ query.data.summary.openCount }}
          </p>
        </div>
      </div>

      <p class="state note section">
        Die Summen folgen demselben Filter wie die Liste — eine Abfrage nach offenen
        Gebühren zeigt den offenen Betrag, nicht den des ganzen Jahres.
      </p>

      <div
        v-if="!query.data.fees.length"
        class="state empty"
      >
        Zu diesem Filter gibt es keine Gebühren.
      </div>

      <div
        v-else
        class="card table-wrap"
      >
        <table class="data">
          <thead>
            <tr>
              <th>Gebühr</th>
              <th>Tippschein</th>
              <th>Zeitraum</th>
              <th class="numeric">
                Betrag
              </th>
              <th>Fällig</th>
              <th>Status</th>
              <th>Bezahlt am</th>
              <th>Weg</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="fee in query.data.fees"
              :key="fee.feeId"
            >
              <td>#{{ fee.feeId }}</td>
              <td>#{{ fee.ticketId }}</td>
              <td>{{ formatDate(fee.periodStart) }} – {{ formatDate(fee.periodEnd) }}</td>
              <td class="numeric">
                {{ formatAmount(fee.amount) }}
              </td>
              <td>{{ formatDate(fee.dueDate) }}</td>
              <td>
                <span
                  class="badge"
                  :class="fee.paymentStatus"
                >{{ statusLabel(fee.paymentStatus) }}</span>
              </td>
              <td>{{ formatDateTime(fee.paidAt) }}</td>
              <td>{{ fee.paymentMethod ?? '–' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </ParticipantScope>
</template>

<script setup>
import { onMounted, reactive, watch } from 'vue'
import ParticipantScope from '@/components/ParticipantScope.vue'
import TippYearPicker from '@/components/TippYearPicker.vue'
import api from '@/services/api'
import { useQuery } from '@/composables/useCommand'
import { useAuthStore } from '@/stores/auth'
import { formatAmount, formatDate, formatDateTime, statusLabel } from '@/support/format'

const authStore = useAuthStore()
const query = useQuery()

const filters = reactive({ tippYearId: '', paymentStatus: '' })

const reload = () => query.load(() => api.getFees(authStore.participantId, { ...filters }))

// Changing a filter is the request. Both are selects, so this cannot fire per
// keystroke, and a "Filtern" button next to a dropdown only ever asked people
// to confirm a choice they had already made.
watch(filters, reload)

onMounted(() => {
  if (authStore.participantId) {
    reload()
  }
})
</script>

<style scoped>
.figure {
  font-size: 1.75rem;
  font-weight: 700;
  color: var(--gray-900);
  font-variant-numeric: tabular-nums;
}

.figure.open {
  color: var(--yellow);
}
</style>
