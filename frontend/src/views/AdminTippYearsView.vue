<template>
  <div class="page-header">
    <div>
      <h2>Tippjahre</h2>
      <p class="subtitle">
        Zeitraum, Perioden, Mitglieder, Tippscheine und die Jahresausschüttung.
      </p>
    </div>
    <button
      class="btn-secondary"
      :disabled="years.loading"
      @click="loadYears"
    >
      Aktualisieren
    </button>
  </div>

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

  <div
    v-else
    class="section"
  >
    <div
      v-if="!tippYears.length"
      class="state empty"
    >
      Noch kein Tippjahr angelegt.
    </div>

    <div
      v-else
      class="card table-wrap"
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
          >
            <td>#{{ year.tippYearId }}</td>
            <td>{{ year.name }}</td>
            <td>{{ formatDate(year.startDate) }} – {{ formatDate(year.endDate) }}</td>
            <td>
              <span
                class="badge"
                :class="year.status"
              >{{ statusLabel(year.status) }}</span>
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
                {{ selectedId === year.tippYearId ? 'ausgewählt' : 'auswählen' }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <p class="state note">
      Ein über HTTP angelegtes Tippjahr steht auf <code>planned</code> und nimmt in diesem
      Zustand keinen Tippschein an. Der Lebenszyklus (<code>start</code>, <code>close</code>)
      ist im Aggregat durchgesetzt, hat aber in der Basisversion bewusst keinen Endpunkt.
    </p>
  </div>

  <!-- B-10 -->
  <div class="card section">
    <h3>Tippjahr anlegen</h3>
    <form @submit.prevent="createTippYear">
      <div class="field-row">
        <div class="field">
          <label for="ty-name">Name</label>
          <input
            id="ty-name"
            v-model="newYear.name"
            required
            placeholder="Tippjahr 2026"
          >
        </div>
        <div class="field">
          <label for="ty-start">Beginn</label>
          <input
            id="ty-start"
            v-model="newYear.startDate"
            type="date"
            required
          >
        </div>
        <div class="field">
          <label for="ty-end">Ende</label>
          <input
            id="ty-end"
            v-model="newYear.endDate"
            type="date"
            required
          >
        </div>
        <div class="field">
          <label for="ty-cost">Preis je Reihe und Ziehung</label>
          <input
            id="ty-cost"
            v-model="newYear.ticketCostPerRow"
            type="number"
            step="0.01"
            min="0"
            required
          >
        </div>
      </div>
      <button
        class="btn-primary"
        :disabled="createYear.pending"
        type="submit"
      >
        {{ createYear.pending ? 'Wird gesendet …' : 'Anlegen' }}
      </button>
      <CommandFeedback :command="createYear" />
    </form>
  </div>

  <template v-if="selectedId">
    <h2 class="section-title">
      Tippjahr #{{ selectedId }}
    </h2>

    <!-- B-14 -->
    <div class="card section">
      <h3>Tippperioden</h3>

      <div
        v-if="periods.loading"
        class="state loading"
      >
        Wird geladen …
      </div>
      <div
        v-else-if="periods.error"
        class="state error"
      >
        {{ periods.error }}
      </div>
      <div
        v-else-if="!betPeriods.length"
        class="state empty"
      >
        Noch keine Periode angelegt. Ohne Periode gibt es keine Tippreihe.
      </div>
      <div
        v-else
        class="table-wrap section"
      >
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

      <form @submit.prevent="createBetPeriod">
        <div class="field-row">
          <div class="field">
            <label for="bp-name">Name</label>
            <input
              id="bp-name"
              v-model="newPeriod.name"
              required
              placeholder="Q1 2026"
            >
          </div>
          <div class="field">
            <label for="bp-start">Beginn</label>
            <input
              id="bp-start"
              v-model="newPeriod.startDate"
              type="date"
              required
            >
          </div>
          <div class="field">
            <label for="bp-end">Ende</label>
            <input
              id="bp-end"
              v-model="newPeriod.endDate"
              type="date"
              required
            >
          </div>
          <div class="field">
            <label for="bp-seq">Folge</label>
            <input
              id="bp-seq"
              v-model="newPeriod.sequence"
              type="number"
              min="1"
            >
          </div>
        </div>
        <p class="state note">
          Die Periode muss im Tippjahr liegen und darf keine bestehende überlappen — sonst
          wären zwei Reihen desselben Teilnehmers am selben Tag gültig.
        </p>
        <button
          class="btn-primary"
          :disabled="createPeriod.pending"
          type="submit"
        >
          {{ createPeriod.pending ? 'Wird gesendet …' : 'Periode anlegen' }}
        </button>
        <CommandFeedback :command="createPeriod" />
      </form>
    </div>

    <!-- B-11 -->
    <div class="card section">
      <h3>Teilnehmer aufnehmen</h3>
      <form @submit.prevent="addMember">
        <div class="field-row">
          <div class="field">
            <label for="m-participant">Teilnehmer-ID</label>
            <input
              id="m-participant"
              v-model="newMember.participantId"
              type="number"
              min="1"
              required
            >
          </div>
          <div class="field">
            <label for="m-joined">Beitritt</label>
            <input
              id="m-joined"
              v-model="newMember.joinedAt"
              type="datetime-local"
            >
            <span class="hint">leer = jetzt</span>
          </div>
        </div>
        <button
          class="btn-primary"
          :disabled="addMemberCmd.pending"
          type="submit"
        >
          {{ addMemberCmd.pending ? 'Wird gesendet …' : 'Aufnehmen' }}
        </button>
        <CommandFeedback :command="addMemberCmd" />
      </form>
    </div>

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
        <p class="state note">
          Der Schein bündelt alle Reihen mit aktiver Teilnahme als Snapshot und erzeugt je
          Teilnehmer eine Gebühr. Eine spätere Korrektur einer Reihe ändert ihn nicht mehr.
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
          Summiert alle Scheingewinne des Jahres und teilt sie gleichmäßig auf die
          Teilnehmer auf, unabhängig davon, wie viele Perioden jemand bezahlt hat. Die
          Rundungsdifferenz geht auf den ersten Anteil. Solange das Tippjahr nicht
          <code>closed</code> ist, antwortet der Endpunkt mit 409.
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
import api from '@/services/api'
import { useCommand, useQuery } from '@/composables/useCommand'
import { formatAmount, formatDate, statusLabel } from '@/support/format'

const years = useQuery()
const periods = useQuery()

const createYear = useCommand()
const createPeriod = useCommand()
const addMemberCmd = useCommand()
const submitTicketCmd = useCommand()
const payoutCmd = useCommand()

const selectedId = ref(null)

const tippYears = computed(() => years.data?.tippYears ?? [])
const betPeriods = computed(() => periods.data?.betPeriods ?? [])

const newYear = reactive({ name: '', startDate: '', endDate: '', ticketCostPerRow: '' })
const newPeriod = reactive({ name: '', startDate: '', endDate: '', sequence: '' })
const newMember = reactive({ participantId: '', joinedAt: '' })
const newTicket = reactive({
  periodStart: '',
  periodEnd: '',
  drawCount: '',
  superzahl: '',
  lotteryReference: ''
})
const payout = reactive({ confirm: false, note: '' })

const loadYears = () => years.load(() => api.admin.getTippYears())
const loadPeriods = () => periods.load(() => api.admin.getBetPeriods(selectedId.value))

function select(tippYearId) {
  selectedId.value = tippYearId
  loadPeriods()
}

/**
 * Every command below refreshes the list afterwards.
 *
 * The API answers 202 and describes the write as asynchronous, but it runs
 * synchronously: when the response arrives, the read models are already
 * current, so re-reading immediately is safe rather than a race.
 */
async function createTippYear() {
  const accepted = await createYear.run(key => api.admin.createTippYear({
    name: newYear.name,
    startDate: newYear.startDate,
    endDate: newYear.endDate,
    ticketCostPerRow: Number(newYear.ticketCostPerRow)
  }, key))

  if (accepted) {
    await loadYears()
  }
}

async function createBetPeriod() {
  const accepted = await createPeriod.run(key => api.admin.createBetPeriod(selectedId.value, {
    name: newPeriod.name,
    startDate: newPeriod.startDate,
    endDate: newPeriod.endDate,
    ...(newPeriod.sequence === '' ? {} : { sequence: Number(newPeriod.sequence) })
  }, key))

  if (accepted) {
    await loadPeriods()
  }
}

async function addMember() {
  const accepted = await addMemberCmd.run(key => api.admin.addMember(selectedId.value, {
    participantId: Number(newMember.participantId),
    ...(newMember.joinedAt === '' ? {} : { joinedAt: newMember.joinedAt })
  }, key))

  if (accepted) {
    await loadYears()
  }
}

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

onMounted(loadYears)
</script>

<style scoped>
.section-title {
  color: var(--gray-900);
  font-size: 1.25rem;
  margin: 2rem 0 1rem;
  padding-top: 1rem;
  border-top: 2px solid var(--gray-300);
}
</style>
