<template>
  <p class="back">
    <RouterLink :to="{ name: 'AdminTippYears' }">
      ← Alle Tippjahre
    </RouterLink>
  </p>

  <div
    v-if="years.loading && !year"
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
  <div
    v-else-if="!year"
    class="state empty"
  >
    Das Tippjahr #{{ tippYearId }} gibt es nicht.
  </div>

  <template v-else>
    <div class="page-header">
      <div>
        <h1>{{ year.name }}</h1>
        <p class="subtitle">
          #{{ year.tippYearId }} · {{ formatDate(year.startDate) }} –
          {{ formatDate(year.endDate) }} · {{ formatAmount(year.ticketCostPerRow) }} je Reihe
          und Ziehung
        </p>
      </div>
      <div class="header-actions">
        <!-- B-18: derselbe Statuswechsel wie in der Liste, hier am Jahr selbst -->
        <TippYearStatusSelect
          :year="year"
          @changed="reload"
        />
        <button
          class="btn-secondary"
          :disabled="years.loading || periods.loading"
          @click="reload"
        >
          Aktualisieren
        </button>
      </div>
    </div>

    <!-- What is still missing on this year, with the matching action in place -->
    <TippYearChecklist
      class="section"
      :year="year"
      :periods="betPeriods"
      :participants="participants"
      :running-year="runningYear"
      @changed="reload"
    />

    <div
      v-if="betPeriods.length"
      class="card section table-wrap"
    >
      <h3>Tippperioden</h3>
      <table class="data">
        <thead>
          <tr>
            <th scope="col">
              ID
            </th>
            <th scope="col">
              Name
            </th>
            <th scope="col">
              Zeitraum
            </th>
            <th
              scope="col"
              class="numeric"
            >
              Folge
            </th>
            <th
              scope="col"
              class="numeric"
            >
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
            <label for="t-start">Abgabe am</label>
            <input
              id="t-start"
              v-model="newTicket.periodStart"
              type="date"
              required
            >
          </div>
          <div class="field">
            <label for="t-weeks">Laufzeit (Wochen)</label>
            <input
              id="t-weeks"
              v-model="newTicket.durationWeeks"
              type="number"
              min="1"
              max="52"
              required
            >
            <span class="hint">1–52, gezählt ab dem Abgabetag</span>
          </div>
          <div class="field">
            <label for="t-days">Ziehungstage</label>
            <select
              id="t-days"
              v-model="newTicket.drawDays"
            >
              <option
                v-for="option in DRAW_DAY_OPTIONS"
                :key="option.value"
                :value="option.value"
              >
                {{ option.label }}
              </option>
            </select>
            <span class="hint">gezogen wird auch an Feiertagen</span>
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
          v-if="year.status !== 'running'"
          class="state note"
        >
          <strong>{{ year.name }} läuft nicht.</strong> Ein Tippschein wird nur von
          einem laufenden Tippjahr angenommen — siehe die Checkliste oben.
        </p>
        <p class="state note">
          Der Schein übernimmt alle Reihen mit aktiver Teilnahme und erzeugt je Teilnehmer
          eine Gebühr. Eine spätere Korrektur einer Reihe ändert ihn nicht mehr.
        </p>
        <!--
          Zeitraum und Ziehungen werden nicht eingegeben, sondern berechnet — hier
          stehen sie, bevor der Schein abgeschickt wird, statt danach in der Tabelle.
        -->
        <p
          v-if="schedule !== null"
          class="state note"
        >
          Der Schein läuft bis <strong>{{ formatDate(schedule.periodEnd) }}</strong> und nimmt
          an <strong>{{ schedule.drawCount }}</strong> Ziehungen teil.
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
import { computed, onMounted, reactive, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import CommandFeedback from '@/components/CommandFeedback.vue'
import TippYearChecklist from '@/components/TippYearChecklist.vue'
import TippYearStatusSelect from '@/components/TippYearStatusSelect.vue'
import api from '@/services/api'
import { useCommand, useQuery } from '@/composables/useCommand'
import { formatAmount, formatDate } from '@/support/format'
import { applicableProcessingFee } from '@/support/processingFee'
import { DRAW_DAY_OPTIONS, drawSchedule } from '@/support/drawSchedule'

/**
 * One tipp year, at its own address.
 *
 * This used to open underneath the list: a click on a row unfolded the
 * checklist, the periods and two forms below twenty other years, with nothing
 * in the URL to say which year one was looking at. A page of its own is what a
 * link can point at, a browser can go back from and a bookmark can keep.
 */
const route = useRoute()

const tippYearId = computed(() => Number(route.params.tippYearId))

const years = useQuery()
const periods = useQuery()
const people = useQuery()

const submitTicketCmd = useCommand()
const payoutCmd = useCommand()

// There is no endpoint for a single tipp year - the list is the read model,
// and picking out of it costs one request either way.
const year = computed(() =>
  (years.data?.tippYears ?? []).find(candidate => candidate.tippYearId === tippYearId.value) ?? null
)

const betPeriods = computed(() => periods.data?.betPeriods ?? [])
const participants = computed(() => people.data?.participants ?? [])

// B-18 allows exactly one running year at a time; the checklist uses this to
// say *why* a start is unavailable rather than leaving a button that 409s.
const runningYear = computed(() =>
  (years.data?.tippYears ?? []).find(candidate => candidate.status === 'running') ?? null
)

const newTicket = reactive({
  periodStart: '',
  // Both draw days is what the syndicate plays; the Laufzeit is the one number
  // that genuinely differs from ticket to ticket.
  durationWeeks: '',
  drawDays: 'both',
  superzahl: '',
  lotteryReference: ''
})
const payout = reactive({ confirm: false, note: '' })

// What the API will make of the form: neither the end of the period nor the
// number of draws is entered, both follow from the Laufzeit.
const schedule = computed(() => drawSchedule(
  newTicket.periodStart,
  newTicket.durationWeeks,
  newTicket.drawDays
))

// Shown while the form is being filled in, so the cost is visible before the
// ticket is submitted rather than after. The API decides the actual rate.
const applicableFee = computed(() => applicableProcessingFee(
  newTicket.periodStart,
  schedule.value?.periodEnd,
  year.value
))

function reload() {
  return Promise.all([
    years.load(() => api.admin.getTippYears()),
    periods.load(() => api.admin.getBetPeriods(tippYearId.value))
  ])
}

/**
 * Every command below refreshes the page afterwards.
 *
 * The API answers 202 and describes the write as asynchronous, but it runs
 * synchronously: when the response arrives, the read models are already
 * current, so re-reading immediately is safe rather than a race.
 */
async function submitTicket() {
  const accepted = await submitTicketCmd.run(key => api.admin.submitTicket(tippYearId.value, {
    periodStart: newTicket.periodStart,
    durationWeeks: Number(newTicket.durationWeeks),
    drawDays: newTicket.drawDays,
    ...(newTicket.superzahl === '' ? {} : { superzahl: Number(newTicket.superzahl) }),
    ...(newTicket.lotteryReference === '' ? {} : { lotteryReference: newTicket.lotteryReference })
  }, key))

  if (accepted) {
    await reload()
  }
}

async function distributePayout() {
  const accepted = await payoutCmd.run(key => api.admin.distributePayout(tippYearId.value, {
    confirm: payout.confirm,
    ...(payout.note === '' ? {} : { note: payout.note })
  }, key))

  if (accepted) {
    payout.confirm = false
    await reload()
  }
}

// The route parameter can change without the component being torn down - two
// years are the same page to the router.
watch(tippYearId, reload)

onMounted(() => {
  reload()
  // The checklist offers these for "Teilnehmer aufnehmen", so only the ones
  // still playing (B-25) - B-11 refuses the others anyway.
  people.load(() => api.admin.getParticipants(true))
})
</script>

<style scoped>
.back {
  margin-bottom: 0.5rem;
  font-size: 0.875rem;
}

.header-actions {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.section-title {
  color: var(--gray-900);
  font-size: 1.25rem;
  margin: 2rem 0 1rem;
  padding-top: 1rem;
  border-top: 2px solid var(--gray-300);
}
</style>
