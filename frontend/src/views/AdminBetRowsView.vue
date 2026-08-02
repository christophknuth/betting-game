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
        <select
          id="tippYear"
          v-model="tippYearId"
          @change="loadPeriods"
        >
          <option value="">
            bitte wählen
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
        <label for="betPeriod">Tippperiode</label>
        <select
          id="betPeriod"
          v-model="form.betPeriodId"
          :disabled="!betPeriods.length"
        >
          <option value="">
            bitte wählen
          </option>
          <option
            v-for="period in betPeriods"
            :key="period.betPeriodId"
            :value="period.betPeriodId"
          >
            {{ period.name }} ({{ formatDate(period.startDate) }} – {{ formatDate(period.endDate) }})
          </option>
        </select>
      </div>
    </div>

    <div
      v-if="years.error"
      class="state error"
    >
      {{ years.error }}
    </div>
    <div
      v-else-if="periods.error"
      class="state error"
    >
      {{ periods.error }}
    </div>
    <div
      v-else-if="tippYearId && !periods.loading && !betPeriods.length"
      class="state empty"
    >
      Für dieses Tippjahr ist keine Periode angelegt. Ohne Periode gibt es keine Reihe.
    </div>
  </div>

  <!-- B-06 -->
  <div class="card section">
    <h3>Reihe eintragen</h3>

    <form @submit.prevent="assign">
      <div class="field">
        <label for="participantId">Teilnehmer</label>
        <select
          id="participantId"
          v-model="form.participantId"
          required
        >
          <option value="">
            bitte wählen
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
        <span class="label">Sechs Zahlen</span>
        <NumberGrid v-model="form.numbers" />
      </div>

      <div class="field">
        <label for="replaceReason">Grund der Ersetzung</label>
        <input
          id="replaceReason"
          v-model="form.replaceReason"
          placeholder="nur beim Ersetzen"
        >
        <span class="hint">
          Nur nötig, um eine bereits zugeordnete Reihe zu ersetzen. Ohne den Grund
          antwortet der Endpunkt mit 409 — regulär wechselt die Reihe erst mit der
          nächsten Periode.
        </span>
      </div>

      <button
        class="btn-primary"
        :disabled="command.pending || !form.betPeriodId || form.numbers.length !== 6"
        type="submit"
      >
        {{ command.pending ? 'Wird gesendet …' : 'Reihe zuordnen' }}
      </button>

      <CommandFeedback :command="command" />
    </form>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import CommandFeedback from '@/components/CommandFeedback.vue'
import NumberGrid from '@/components/NumberGrid.vue'
import api from '@/services/api'
import { useCommand, useQuery } from '@/composables/useCommand'
import { formatDate } from '@/support/format'

const years = useQuery()
const periods = useQuery()
const people = useQuery()
const command = useCommand()

const tippYearId = ref('')

const form = reactive({
  participantId: '',
  betPeriodId: '',
  numbers: [],
  replaceReason: ''
})

const tippYears = computed(() => years.data?.tippYears ?? [])
const betPeriods = computed(() => periods.data?.betPeriods ?? [])
const participants = computed(() => people.data?.participants ?? [])

function loadPeriods() {
  form.betPeriodId = ''

  if (tippYearId.value) {
    periods.load(() => api.admin.getBetPeriods(tippYearId.value))
  }
}

async function assign() {
  // No parsing and no check of the six numbers left to do here: the grid hands
  // over six distinct numbers from 1 to 49, ascending, or the submit button is
  // not enabled at all.
  const accepted = await command.run(key => api.admin.assignBetRow(form.participantId, {
    betPeriodId: Number(form.betPeriodId),
    numbers: [...form.numbers],
    ...(form.replaceReason === '' ? {} : { replaceReason: form.replaceReason })
  }, key))

  if (accepted) {
    form.numbers = []
    form.replaceReason = ''
    // The row count per period changes with the assignment, so the list the
    // administrator is looking at is stale the moment this succeeds.
    periods.load(() => api.admin.getBetPeriods(tippYearId.value))
  }
}

onMounted(() => {
  years.load(() => api.admin.getTippYears())
  people.load(() => api.admin.getParticipants())
})
</script>
