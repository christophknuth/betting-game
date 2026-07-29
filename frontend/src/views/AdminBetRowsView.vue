<template>
  <div class="page-header">
    <div>
      <h2>Tippreihe zuordnen</h2>
      <p class="subtitle">
        Pro Teilnehmer und Periode genau eine Reihe. In der Basisversion ist das der einzige
        Schreibzugriff auf einen Teilnehmer — Selbstverwaltung ist E1.
      </p>
    </div>
  </div>

  <div class="card section">
    <h3>Tippperiode wählen</h3>

    <div class="field-inline">
      <div class="field">
        <label for="tippYear">Tippjahr</label>
        <select id="tippYear" v-model="tippYearId" @change="loadPeriods">
          <option value="">bitte wählen</option>
          <option v-for="year in tippYears" :key="year.tippYearId" :value="year.tippYearId">
            {{ year.name }} (#{{ year.tippYearId }})
          </option>
        </select>
      </div>

      <div class="field">
        <label for="betPeriod">Tippperiode</label>
        <select id="betPeriod" v-model="form.betPeriodId" :disabled="!betPeriods.length">
          <option value="">bitte wählen</option>
          <option v-for="period in betPeriods" :key="period.betPeriodId" :value="period.betPeriodId">
            {{ period.name }} ({{ formatDate(period.startDate) }} – {{ formatDate(period.endDate) }})
          </option>
        </select>
      </div>
    </div>

    <div v-if="years.error" class="state error">{{ years.error }}</div>
    <div v-else-if="periods.error" class="state error">{{ periods.error }}</div>
    <div v-else-if="tippYearId && !periods.loading && !betPeriods.length" class="state empty">
      Für dieses Tippjahr ist keine Periode angelegt. Ohne Periode gibt es keine Reihe.
    </div>
  </div>

  <!-- B-06 -->
  <div class="card section">
    <h3>Reihe eintragen</h3>

    <form @submit.prevent="assign">
      <div class="field-row">
        <div class="field">
          <label for="participantId">Teilnehmer-ID</label>
          <input id="participantId" v-model="form.participantId" type="number" min="1" required>
        </div>
        <div class="field">
          <label for="numbers">Sechs Zahlen</label>
          <input id="numbers" v-model="form.numbers" placeholder="3 12 19 27 33 45" required>
          <span class="hint">Trennzeichen egal: Leerzeichen, Komma oder Semikolon</span>
        </div>
      </div>

      <div class="field">
        <label for="replaceReason">Grund der Ersetzung</label>
        <input id="replaceReason" v-model="form.replaceReason" placeholder="nur beim Ersetzen">
        <span class="hint">
          Nur nötig, um eine bereits zugeordnete Reihe zu ersetzen. Ohne den Grund
          antwortet der Endpunkt mit 409 — regulär wechselt die Reihe erst mit der
          nächsten Periode.
        </span>
      </div>

      <div v-if="numbersError" class="state error">{{ numbersError }}</div>

      <button class="btn-primary" :disabled="command.pending || !form.betPeriodId" type="submit">
        {{ command.pending ? 'Wird gesendet …' : 'Reihe zuordnen' }}
      </button>

      <CommandFeedback :command="command" />
    </form>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import CommandFeedback from '@/components/CommandFeedback.vue'
import api from '@/services/api'
import { useCommand, useQuery } from '@/composables/useCommand'
import { formatDate, parseNumbers } from '@/support/format'

const years = useQuery()
const periods = useQuery()
const command = useCommand()

const tippYearId = ref('')
const numbersError = ref(null)

const form = reactive({
  participantId: '',
  betPeriodId: '',
  numbers: '',
  replaceReason: ''
})

const tippYears = computed(() => years.data?.tippYears ?? [])
const betPeriods = computed(() => periods.data?.betPeriods ?? [])

function loadPeriods() {
  form.betPeriodId = ''

  if (tippYearId.value) {
    periods.load(() => api.admin.getBetPeriods(tippYearId.value))
  }
}

async function assign() {
  // The six numbers are validated here only to save the round trip; the domain
  // enforces the same rule in LottoNumbers and would answer 400.
  const { numbers, error } = parseNumbers(form.numbers)
  numbersError.value = error

  if (error) {
    return
  }

  const accepted = await command.run(key => api.admin.assignBetRow(form.participantId, {
    betPeriodId: Number(form.betPeriodId),
    numbers,
    ...(form.replaceReason === '' ? {} : { replaceReason: form.replaceReason })
  }, key))

  if (accepted) {
    form.replaceReason = ''
    // The row count per period changes with the assignment, so the list the
    // administrator is looking at is stale the moment this succeeds.
    periods.load(() => api.admin.getBetPeriods(tippYearId.value))
  }
}

onMounted(() => years.load(() => api.admin.getTippYears()))
</script>
