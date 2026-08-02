<template>
  <div class="page-header">
    <div>
      <h1>Gebühren</h1>
      <p class="subtitle">
        Alle Tippgebühren mit Zahlungsstatus — die Übersicht über die offenen Posten (B-07).
      </p>
    </div>
  </div>

  <div class="card section">
    <div class="field-inline">
      <div class="field">
        <label for="tippYearId">Tippjahr</label>
        <select
          id="tippYearId"
          v-model="filters.tippYearId"
        >
          <option value="">
            alle
          </option>
          <option
            v-for="year in tippYears"
            :key="year.tippYearId"
            :value="year.tippYearId"
          >
            {{ year.name }} (#{{ year.tippYearId }})
          </option>
        </select>
      </div>
      <div class="field">
        <label for="participantId">Teilnehmer</label>
        <select
          id="participantId"
          v-model="filters.participantId"
        >
          <option value="">
            alle
          </option>
          <option
            v-for="participant in participants"
            :key="participant.participantId"
            :value="participant.participantId"
          >
            {{ participant.displayName }} (#{{ participant.participantId }})
          </option>
        </select>
      </div>
      <div class="field">
        <label for="paymentStatus">Status</label>
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
      <button
        class="btn-secondary"
        :disabled="query.loading"
        @click="reload"
      >
        Aktualisieren
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
    class="state error"
  >
    {{ query.error }}
  </div>
  <div
    v-else-if="!fees.length"
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
          <th scope="col">
            Gebühr
          </th>
          <th scope="col">
            Teilnehmer
          </th>
          <th scope="col">
            Schein
          </th>
          <th scope="col">
            Zeitraum
          </th>
          <th
            scope="col"
            class="numeric"
          >
            Betrag
          </th>
          <th scope="col">
            Fällig
          </th>
          <th scope="col">
            Status
          </th>
          <th scope="col">
            Gebucht von
          </th>
          <th scope="col" />
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="fee in fees"
          :key="fee.feeId"
        >
          <td>#{{ fee.feeId }}</td>
          <td>{{ fee.displayName }} (#{{ fee.participantId }})</td>
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
          <td>{{ fee.bookedBy ?? '–' }}</td>
          <td>
            <button
              class="btn-link"
              @click="open(fee)"
            >
              buchen
            </button>
          </td>
        </tr>
      </tbody>
    </table>
  </div>

  <div
    v-if="selected"
    class="card section"
  >
    <h3>Gebühr #{{ selected.feeId }} buchen</h3>
    <p class="subtitle">
      {{ selected.displayName }} · {{ formatAmount(selected.amount) }} ·
      fällig {{ formatDate(selected.dueDate) }}
    </p>

    <form @submit.prevent="record">
      <div class="field-row">
        <div class="field">
          <label for="status">Zahlungsstatus</label>
          <select
            id="status"
            v-model="booking.paymentStatus"
          >
            <option value="paid">
              bezahlt
            </option>
            <option value="open">
              offen
            </option>
            <option value="waived">
              erlassen
            </option>
          </select>
        </div>
        <div class="field">
          <label for="paidAt">Bezahlt am</label>
          <input
            id="paidAt"
            v-model="booking.paidAt"
            type="datetime-local"
          >
        </div>
        <div class="field">
          <label for="method">Zahlungsweg</label>
          <select
            id="method"
            v-model="booking.paymentMethod"
          >
            <option value="">
              –
            </option>
            <option value="bank_transfer">
              Überweisung
            </option>
            <option value="paypal">
              PayPal
            </option>
            <option value="cash">
              bar
            </option>
            <option value="other">
              sonstiges
            </option>
          </select>
        </div>
      </div>

      <div class="field">
        <label for="note">Notiz</label>
        <input
          id="note"
          v-model="booking.note"
        >
        <span class="hint">Beim Erlass einer Gebühr erforderlich.</span>
      </div>

      <!--
        Gone: an explanation that the API takes the booking user from the token
        rather than from the client. True, and a property of the security model
        rather than anything the person booking a payment decides.
      -->

      <div class="actions">
        <button
          class="btn-primary"
          :disabled="command.pending"
          type="submit"
        >
          {{ command.pending ? 'Wird gesendet …' : 'Buchen' }}
        </button>
        <button
          class="btn-secondary"
          type="button"
          @click="selected = null"
        >
          Abbrechen
        </button>
      </div>

      <CommandFeedback :command="command" />
    </form>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import CommandFeedback from '@/components/CommandFeedback.vue'
import api from '@/services/api'
import { useCommand, useQuery } from '@/composables/useCommand'
import { formatAmount, formatDate, statusLabel } from '@/support/format'

const query = useQuery()
const years = useQuery()
const people = useQuery()
const command = useCommand()

const filters = reactive({ tippYearId: '', participantId: '', paymentStatus: '' })
const selected = ref(null)
const booking = reactive({ paymentStatus: 'paid', paidAt: '', paymentMethod: '', note: '' })

const fees = computed(() => query.data?.fees ?? [])

// The two filters used to be numeric ids typed from memory. Both lists exist as
// admin endpoints and are what the other admin views already choose from.
const tippYears = computed(() => years.data?.tippYears ?? [])
const participants = computed(() => people.data?.participants ?? [])

const reload = () => query.load(() => api.admin.getFees({ ...filters }))

// A changed filter loads by itself; the button beside them is for fetching the
// same filter again, after somebody else has booked something.
watch(filters, reload)

function open(fee) {
  selected.value = fee
  command.reset()

  booking.paymentStatus = fee.paymentStatus === 'open' ? 'paid' : fee.paymentStatus
  booking.paidAt = ''
  booking.paymentMethod = fee.paymentMethod ?? ''
  booking.note = ''
}

async function record() {
  const accepted = await command.run(key => api.admin.recordFeePayment(selected.value.feeId, {
    paymentStatus: booking.paymentStatus,
    ...(booking.paidAt === '' ? {} : { paidAt: booking.paidAt }),
    ...(booking.paymentMethod === '' ? {} : { paymentMethod: booking.paymentMethod }),
    ...(booking.note === '' ? {} : { note: booking.note })
  }, key))

  if (accepted) {
    await reload()
    // Kept open, pointed at the refreshed row: closing it would take the
    // command id away with it. Gone from the list under the current filter
    // (booked as paid while filtering for open ones) closes it after all.
    selected.value = fees.value.find(fee => fee.feeId === selected.value.feeId) ?? null
  }
}

onMounted(() => {
  reload()
  years.load(() => api.admin.getTippYears())
  people.load(() => api.admin.getParticipants())
})
</script>
