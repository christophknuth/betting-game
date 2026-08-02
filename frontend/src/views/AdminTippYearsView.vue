<template>
  <div class="page-header">
    <div>
      <h2>Tippjahre</h2>
      <p class="subtitle">
        Einrichten, betreiben und am Jahresende ausschütten.
      </p>
    </div>
    <div class="header-actions">
      <button
        class="btn-secondary"
        :disabled="years.loading"
        @click="loadYears"
      >
        Aktualisieren
      </button>
      <button
        v-if="!wizardOpen"
        class="btn-primary"
        @click="openWizard"
      >
        Neues Tippjahr
      </button>
    </div>
  </div>

  <!-- B-10, B-14, B-11, B-18 in the order they have to happen -->
  <TippYearSetupWizard
    v-if="wizardOpen"
    :participants="participants"
    :running-year="runningYear"
    @finished="finishWizard"
    @cancel="wizardOpen = false"
  />

  <div
    v-if="years.loading"
    class="state loading"
  >
    Wird geladen …
  </div>
  <div
    v-else-if="years.error"
    class="state error"
  >
    {{ years.error }}
  </div>

  <template v-else>
    <div
      v-if="!tippYears.length && !wizardOpen"
      class="state empty"
    >
      Noch kein Tippjahr angelegt. <strong>Neues Tippjahr</strong> führt durch die
      Einrichtung.
    </div>

    <div
      v-else-if="tippYears.length"
      class="section card table-wrap"
    >
      <table class="data">
        <thead>
          <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Zeitraum</th>
            <th>Status</th>
            <th class="numeric">
              Reihenpreis
            </th>
            <th class="numeric">
              Mitglieder
            </th>
            <th class="numeric">
              Scheine
            </th>
            <th class="numeric">
              Ziehungen
            </th>
            <th class="numeric">
              Gewinne
            </th>
            <th />
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="year in tippYears"
            :key="year.tippYearId"
            :class="{ selected: selectedId === year.tippYearId }"
          >
            <td>#{{ year.tippYearId }}</td>
            <td>{{ year.name }}</td>
            <td>{{ formatDate(year.startDate) }} – {{ formatDate(year.endDate) }}</td>
            <!-- B-18: every transition is allowed, hence a dropdown rather
                 than a row of buttons for whatever the next step would be. -->
            <td>
              <select
                :value="year.status"
                class="status-select"
                :class="year.status"
                :disabled="statusCommands[year.tippYearId]?.pending"
                :aria-label="`Status von ${year.name}`"
                @change="changeStatus(year, $event)"
              >
                <option
                  v-for="status in TIPP_YEAR_STATUSES"
                  :key="status"
                  :value="status"
                >
                  {{ statusLabel(status) }}
                </option>
              </select>
            </td>
            <td class="numeric">
              {{ formatAmount(year.ticketCostPerRow) }}
            </td>
            <td class="numeric">
              {{ year.memberCount }}
            </td>
            <td class="numeric">
              {{ year.ticketCount }}
            </td>
            <td class="numeric">
              {{ year.drawCount }}
            </td>
            <td class="numeric">
              {{ formatAmount(year.totalWinnings) }}
            </td>
            <td>
              <button
                class="btn-link"
                @click="select(year.tippYearId)"
              >
                {{ selectedId === year.tippYearId ? 'ausgewählt' : 'öffnen' }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <p
      v-if="tippYears.length"
      class="state note"
    >
      <strong>Laufen darf immer nur ein Tippjahr.</strong> Nur ein laufendes nimmt
      Tippscheine an, ausgeschüttet wird nur aus einem abgeschlossenen. Der Status lässt
      sich jederzeit auch zurücksetzen.
    </p>
  </template>

  <template v-if="selectedYear">
    <!-- What is still missing on this year, with the matching action in place -->
    <TippYearChecklist
      class="section"
      :year="selectedYear"
      :periods="betPeriods"
      :participants="participants"
      :running-year="runningYear"
      @changed="reloadSelected"
    />

    <div
      v-if="betPeriods.length"
      class="card section table-wrap"
    >
      <h3>Tippperioden</h3>
      <table class="data">
        <thead>
          <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Zeitraum</th>
            <th class="numeric">
              Folge
            </th>
            <th class="numeric">
              Reihen
            </th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="period in betPeriods"
            :key="period.betPeriodId"
          >
            <td>#{{ period.betPeriodId }}</td>
            <td>{{ period.name }}</td>
            <td>{{ formatDate(period.startDate) }} – {{ formatDate(period.endDate) }}</td>
            <td class="numeric">
              {{ period.sequence }}
            </td>
            <td class="numeric">
              {{ period.betRowCount }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!--
      Below here is running operations, not setup: a ticket is submitted every
      month and the distribution happens once at the end. Keeping them in the
      same stack of forms as "create a tipp year" was what made the page read
      like a pile of unrelated fields.
    -->
    <h2 class="section-title">
      Laufender Betrieb
    </h2>

    <!-- B-12 -->
    <div class="card section">
      <h3>Tippschein erfassen</h3>
      <form @submit.prevent="submitTicket">
        <div class="field-row">
          <div class="field">
            <label for="t-start">Zeitraum von</label>
            <input
              id="t-start"
              v-model="newTicket.periodStart"
              type="date"
              required
            >
          </div>
          <div class="field">
            <label for="t-end">Zeitraum bis</label>
            <input
              id="t-end"
              v-model="newTicket.periodEnd"
              type="date"
              required
            >
          </div>
          <div class="field">
            <label for="t-draws">Ziehungen</label>
            <input
              id="t-draws"
              v-model="newTicket.drawCount"
              type="number"
              min="1"
              required
            >
          </div>
          <div class="field">
            <label for="t-sz">Superzahl</label>
            <input
              id="t-sz"
              v-model="newTicket.superzahl"
              type="number"
              min="0"
              max="9"
            >
            <span class="hint">aus der Losnummer, gilt für alle Reihen</span>
          </div>
          <div class="field">
            <label for="t-ref">Losnummer / Referenz</label>
            <input
              id="t-ref"
              v-model="newTicket.lotteryReference"
            >
          </div>
        </div>
        <p
          v-if="selectedYear.status !== 'running'"
          class="state note"
        >
          <strong>{{ selectedYear.name }} läuft nicht.</strong> Ein Tippschein wird nur von
          einem laufenden Tippjahr angenommen — siehe die Checkliste oben.
        </p>
        <p class="state note">
          Der Schein übernimmt alle Reihen mit aktiver Teilnahme und erzeugt je Teilnehmer
          eine Gebühr. Eine spätere Korrektur einer Reihe ändert ihn nicht mehr.
        </p>
        <p
          v-if="applicableFee !== null"
          class="state note"
        >
          Für diesen Zeitraum gilt das <strong>{{ applicableFee.label }}</strong>
          Bearbeitungsentgelt von {{ formatAmount(applicableFee.amount) }} — einmal je
          Schein, zusätzlich zu den Reihenkosten.
        </p>
        <button
          class="btn-primary"
          :disabled="submitTicketCmd.pending"
          type="submit"
        >
          {{ submitTicketCmd.pending ? 'Wird gesendet …' : 'Tippschein einreichen' }}
        </button>
        <CommandFeedback :command="submitTicketCmd" />
      </form>
    </div>

    <!-- B-13 -->
    <div class="card section">
      <h3>Jahresausschüttung buchen</h3>
      <form @submit.prevent="distributePayout">
        <div class="field">
          <label for="p-note">Notiz</label>
          <input
            id="p-note"
            v-model="payout.note"
          >
        </div>
        <label class="checkbox">
          <input
            v-model="payout.confirm"
            type="checkbox"
          >
          Ja, ausschütten — die Buchung ist nicht rücknehmbar.
        </label>
        <p class="state note">
          Alle Gewinne des Jahres werden gleichmäßig auf die Teilnehmer verteilt —
          unabhängig davon, wie viele Perioden jemand bezahlt hat. Möglich erst, wenn das
          Tippjahr abgeschlossen ist.
        </p>
        <button
          class="btn-danger"
          :disabled="!payout.confirm || payoutCmd.pending"
          type="submit"
        >
          {{ payoutCmd.pending ? 'Wird gesendet …' : 'Ausschüttung buchen' }}
        </button>
        <CommandFeedback :command="payoutCmd" />
      </form>
    </div>
  </template>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import CommandFeedback from '@/components/CommandFeedback.vue'
import TippYearChecklist from '@/components/TippYearChecklist.vue'
import TippYearSetupWizard from '@/components/TippYearSetupWizard.vue'
import api from '@/services/api'
import { useCommand, useQuery } from '@/composables/useCommand'
import { TIPP_YEAR_STATUSES, formatAmount, formatDate, statusLabel } from '@/support/format'
import { useNotificationStore } from '@/stores/notifications'
import { applicableProcessingFee } from '@/support/processingFee'

const years = useQuery()
const periods = useQuery()
const people = useQuery()

const submitTicketCmd = useCommand()
const payoutCmd = useCommand()

const selectedId = ref(null)
const wizardOpen = ref(false)

const tippYears = computed(() => years.data?.tippYears ?? [])
const betPeriods = computed(() => periods.data?.betPeriods ?? [])
const participants = computed(() => people.data?.participants ?? [])

const selectedYear = computed(() =>
  tippYears.value.find(year => year.tippYearId === selectedId.value) ?? null
)

// B-18 allows exactly one running year at a time; the components use this to
// say *why* a start is unavailable rather than leaving a button that 409s.
const runningYear = computed(() =>
  tippYears.value.find(year => year.status === 'running') ?? null
)

const newTicket = reactive({
  periodStart: '',
  periodEnd: '',
  drawCount: '',
  superzahl: '',
  lotteryReference: ''
})
const payout = reactive({ confirm: false, note: '' })

// Shown while the dates are being filled in, so the cost is visible before the
// ticket is submitted rather than after. The API decides the actual rate.
const applicableFee = computed(() => applicableProcessingFee(
  newTicket.periodStart,
  newTicket.periodEnd,
  selectedYear.value
))

const loadYears = () => years.load(() => api.admin.getTippYears())
const loadPeriods = () => periods.load(() => api.admin.getBetPeriods(selectedId.value))

// --- B-18: Statuswechsel ---

const statusCommands = reactive({})
const notifications = useNotificationStore()

// One command state per year so the idempotency keys cannot get mixed up - a
// key left over from one year must not answer the change of another.
const commandFor = (tippYearId) => (statusCommands[tippYearId] ??= useCommand())

async function changeStatus(year, event) {
  const status = event.target.value

  const command = commandFor(year.tippYearId)
  const accepted = await command.run(
    key => api.admin.changeTippYearStatus(year.tippYearId, { status }, key)
  )

  if (!accepted) {
    // Reported by hand rather than through CommandFeedback: this command has
    // no form of its own, it hangs off a dropdown in a table row.
    notifications.error(command.error)

    // Put the dropdown back by hand. Vue will not do it: the model never
    // changed, so from its side there is nothing to patch - only the DOM is
    // showing a status the server just refused.
    event.target.value = year.status

    return
  }

  await loadYears()
}

function select(tippYearId) {
  selectedId.value = tippYearId
  loadPeriods()
}

function openWizard() {
  wizardOpen.value = true
  selectedId.value = null
}

/** The wizard has written everything itself - open the finished year. */
async function finishWizard(tippYearId) {
  wizardOpen.value = false
  await loadYears()
  select(tippYearId)
}

async function reloadSelected() {
  await Promise.all([loadYears(), loadPeriods()])
}

/**
 * Every command below refreshes the list afterwards.
 *
 * The API answers 202 and describes the write as asynchronous, but it runs
 * synchronously: when the response arrives, the read models are already
 * current, so re-reading immediately is safe rather than a race.
 */
async function submitTicket() {
  const accepted = await submitTicketCmd.run(key => api.admin.submitTicket(selectedId.value, {
    periodStart: newTicket.periodStart,
    periodEnd: newTicket.periodEnd,
    drawCount: Number(newTicket.drawCount),
    ...(newTicket.superzahl === '' ? {} : { superzahl: Number(newTicket.superzahl) }),
    ...(newTicket.lotteryReference === '' ? {} : { lotteryReference: newTicket.lotteryReference })
  }, key))

  if (accepted) {
    await loadYears()
  }
}

async function distributePayout() {
  const accepted = await payoutCmd.run(key => api.admin.distributePayout(selectedId.value, {
    confirm: payout.confirm,
    ...(payout.note === '' ? {} : { note: payout.note })
  }, key))

  if (accepted) {
    payout.confirm = false
    await loadYears()
  }
}

onMounted(() => {
  loadYears()
  people.load(() => api.admin.getParticipants())
})
</script>

<style scoped>
.header-actions {
  display: flex;
  gap: 0.75rem;
}

tr.selected {
  background: var(--gray-50);
}

/* Looks like the badge that used to sit here - but is operable. */
.status-select {
  padding: 0.125rem 0.5rem;
  border: 1px solid var(--gray-300);
  border-radius: 12px;
  font-size: 0.8125rem;
  font-weight: 600;
  background: var(--gray-100);
  color: var(--gray-600);
  cursor: pointer;
}

.status-select:disabled {
  opacity: 0.6;
  cursor: wait;
}

.status-select.running {
  background: #d1fae5;
  color: #065f46;
}

.status-select.planned {
  background: #fef3c7;
  color: #92400e;
}

.status-select.closed,
.status-select.distributed {
  background: #dbeafe;
  color: #1e40af;
}

.section-title {
  color: var(--gray-900);
  font-size: 1.25rem;
  margin: 2rem 0 1rem;
  padding-top: 1rem;
  border-top: 2px solid var(--gray-300);
}
</style>
