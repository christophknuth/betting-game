<template>
  <div class="card wizard">
    <ol class="steps">
      <li
        v-for="(label, index) in STEP_LABELS"
        :key="label"
        :class="{ current: step === index + 1, done: step > index + 1 }"
      >
        <span class="marker">{{ step > index + 1 ? '✓' : index + 1 }}</span>
        {{ label }}
      </li>
    </ol>

    <!-- 1 — Eckdaten (B-10) -->
    <form
      v-if="step === 1"
      @submit.prevent="createYear"
    >
      <h3>Eckdaten des Tippjahres</h3>
      <div class="field-row">
        <div class="field">
          <label for="wz-name">Name</label>
          <input
            id="wz-name"
            v-model="draft.name"
            required
            placeholder="Tippjahr 2027"
          >
        </div>
        <div class="field">
          <label for="wz-start">Beginn</label>
          <input
            id="wz-start"
            v-model="draft.startDate"
            type="date"
            required
          >
        </div>
        <div class="field">
          <label for="wz-end">Ende</label>
          <input
            id="wz-end"
            v-model="draft.endDate"
            type="date"
            required
          >
        </div>
        <div class="field">
          <label for="wz-cost">Preis je Reihe und Ziehung</label>
          <input
            id="wz-cost"
            v-model="draft.ticketCostPerRow"
            type="number"
            step="0.01"
            min="0"
            required
          >
        </div>
      </div>

      <p class="state note">
        Der Zeitraum ist frei wählbar und muss kein Kalenderjahr sein. Mit
        <strong>Weiter</strong> wird das Tippjahr angelegt; es steht dann auf
        <em>geplant</em> und nimmt noch keine Tippscheine an.
      </p>

      <div class="actions">
        <button
          class="btn-secondary"
          type="button"
          @click="$emit('cancel')"
        >
          Abbrechen
        </button>
        <button
          class="btn-primary"
          :disabled="yearCmd.pending"
          type="submit"
        >
          {{ yearCmd.pending ? 'Wird angelegt …' : 'Weiter →' }}
        </button>
      </div>
      <CommandFeedback :command="yearCmd" />
    </form>

    <!-- 2 — Perioden (B-14) -->
    <div v-else-if="step === 2">
      <h3>Tippperioden für {{ createdYear.name }}</h3>
      <p class="subtitle">
        Wie oft darf eine Tippreihe wechseln? Jede Periode trägt genau eine Reihe
        je Teilnehmer.
      </p>

      <div class="templates">
        <label
          v-for="template in PERIOD_TEMPLATES"
          :key="template.id"
          class="template"
          :class="{ chosen: chosenTemplate === template.id }"
        >
          <input
            v-model="chosenTemplate"
            type="radio"
            :value="template.id"
          >
          <span class="template-label">{{ template.label }}</span>
          <span class="template-count">{{ countFor(template) }}</span>
        </label>
      </div>

      <div
        v-if="preview.length"
        class="table-wrap preview"
      >
        <table class="data">
          <thead>
            <tr>
              <th>Folge</th>
              <th>Name</th>
              <th>Zeitraum</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="period in preview"
              :key="period.sequence"
            >
              <td class="numeric">
                {{ period.sequence }}
              </td>
              <td>{{ period.name }}</td>
              <td>{{ formatDate(period.startDate) }} – {{ formatDate(period.endDate) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <p
        v-if="periodBatch.pending"
        class="state loading"
      >
        Periode {{ periodBatch.completed + 1 }} von {{ periodBatch.total }} wird angelegt …
      </p>
      <p
        v-else-if="periodBatch.error"
        class="state error"
      >
        {{ periodBatch.error }}
        <template v-if="periodBatch.completed">
          <br>
          {{ periodBatch.completed }} von {{ periodBatch.total }} Perioden wurden angelegt und
          bleiben bestehen. Ein erneuter Versuch legt nur die restlichen an.
        </template>
      </p>

      <div class="actions">
        <button
          class="btn-secondary"
          type="button"
          @click="step = 3"
        >
          Später festlegen
        </button>
        <button
          class="btn-primary"
          :disabled="!preview.length || periodBatch.pending"
          type="button"
          @click="createPeriods"
        >
          {{ preview.length }} {{ preview.length === 1 ? 'Periode' : 'Perioden' }} anlegen
        </button>
      </div>
    </div>

    <!-- 3 — Teilnehmer (B-11) -->
    <div v-else-if="step === 3">
      <h3>Teilnehmer aufnehmen</h3>
      <p class="subtitle">
        Nur aufgenommene Teilnehmer bekommen eine Tippreihe und eine Gebühr.
      </p>

      <div
        v-if="!participants.length"
        class="state empty"
      >
        Es gibt noch keine Teilnehmer. Unter <strong>Teilnehmer</strong> lassen sich welche
        anlegen — dieser Schritt kann auch später nachgeholt werden.
      </div>

      <div
        v-else
        class="people"
      >
        <label
          v-for="participant in participants"
          :key="participant.participantId"
          class="person"
        >
          <input
            v-model="chosenParticipants"
            type="checkbox"
            :value="participant.participantId"
          >
          {{ participant.displayName }}
          <span class="person-id">#{{ participant.participantId }}</span>
        </label>
      </div>

      <p
        v-if="memberBatch.pending"
        class="state loading"
      >
        Teilnehmer {{ memberBatch.completed + 1 }} von {{ memberBatch.total }} wird
        aufgenommen …
      </p>
      <p
        v-else-if="memberBatch.error"
        class="state error"
      >
        {{ memberBatch.error }}
        <template v-if="memberBatch.completed">
          <br>
          {{ memberBatch.completed }} von {{ memberBatch.total }} wurden aufgenommen.
        </template>
      </p>

      <div class="actions">
        <button
          class="btn-secondary"
          type="button"
          @click="step = 4"
        >
          Später festlegen
        </button>
        <button
          class="btn-primary"
          :disabled="!chosenParticipants.length || memberBatch.pending"
          type="button"
          @click="addMembers"
        >
          {{ chosenParticipants.length }} aufnehmen
        </button>
      </div>
    </div>

    <!-- 4 — Start (B-18) -->
    <div v-else>
      <h3>{{ createdYear.name }} starten</h3>

      <dl class="facts">
        <dt>Zeitraum</dt>
        <dd>{{ formatDate(createdYear.startDate) }} – {{ formatDate(createdYear.endDate) }}</dd>
        <dt>Perioden</dt>
        <dd>{{ periodCount }}</dd>
        <dt>Teilnehmer</dt>
        <dd>{{ memberCount }}</dd>
      </dl>

      <p
        v-if="blockingYear"
        class="state note"
      >
        <strong>{{ blockingYear.name }} läuft bereits.</strong> Es darf immer nur ein Tippjahr
        laufen, deshalb müsste dieses erst geschlossen werden. Das Tippjahr bleibt so lange
        auf <em>geplant</em> — starten lässt es sich später jederzeit.
      </p>
      <p
        v-else
        class="state note"
      >
        Erst ein laufendes Tippjahr nimmt Tippscheine an. Der Status ist danach frei
        änderbar, auch zurück.
      </p>

      <div class="actions">
        <button
          class="btn-secondary"
          type="button"
          @click="$emit('finished', createdYear.tippYearId)"
        >
          Ohne Start beenden
        </button>
        <button
          class="btn-primary"
          :disabled="!!blockingYear || startCmd.pending"
          type="button"
          @click="start"
        >
          {{ startCmd.pending ? 'Wird gestartet …' : 'Tippjahr starten' }}
        </button>
      </div>
      <CommandFeedback :command="startCmd" />
    </div>
  </div>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import CommandFeedback from '@/components/CommandFeedback.vue'
import api from '@/services/api'
import { useBatch, useCommand } from '@/composables/useCommand'
import { PERIOD_TEMPLATES, generateBetPeriods } from '@/support/betPeriods'
import { formatDate } from '@/support/format'

const props = defineProps({
  participants: { type: Array, default: () => [] },
  runningYear: { type: Object, default: null }
})

const emit = defineEmits(['finished', 'cancel'])

const STEP_LABELS = ['Eckdaten', 'Perioden', 'Teilnehmer', 'Start']

const step = ref(1)
const createdYear = ref(null)

const draft = reactive({ name: '', startDate: '', endDate: '', ticketCostPerRow: '1.20' })
const chosenTemplate = ref('month')
const chosenParticipants = ref([])

const periodCount = ref(0)
const memberCount = ref(0)

const yearCmd = useCommand()
const startCmd = useCommand()
const periodBatch = useBatch()
const memberBatch = useBatch()

// A year already running blocks this one (B-18). Checked here only to explain
// why the button is disabled - the unique key on tipp_year.running_marker is
// what actually decides.
const blockingYear = computed(() => props.runningYear)

const preview = computed(() => {
  if (!createdYear.value) {
    return []
  }

  const template = PERIOD_TEMPLATES.find(t => t.id === chosenTemplate.value)

  return generateBetPeriods(
    createdYear.value.startDate,
    createdYear.value.endDate,
    template?.monthsPerPeriod ?? null
  )
})

function countFor(template) {
  if (!createdYear.value) {
    return ''
  }

  const count = generateBetPeriods(
    createdYear.value.startDate,
    createdYear.value.endDate,
    template.monthsPerPeriod
  ).length

  return count === 1 ? '1 Periode' : `${count} Perioden`
}

async function createYear() {
  const accepted = await yearCmd.run(key => api.admin.createTippYear({
    name: draft.name,
    startDate: draft.startDate,
    endDate: draft.endDate,
    ticketCostPerRow: Number(draft.ticketCostPerRow)
  }, key))

  if (!accepted) {
    return
  }

  createdYear.value = {
    tippYearId: accepted.resourceId,
    name: draft.name,
    startDate: draft.startDate,
    endDate: draft.endDate
  }
  step.value = 2
}

async function createPeriods() {
  const finished = await periodBatch.run(
    preview.value,
    (period, key) => api.admin.createBetPeriod(createdYear.value.tippYearId, {
      name: period.name,
      startDate: period.startDate,
      endDate: period.endDate,
      sequence: period.sequence
    }, key)
  )

  periodCount.value = periodBatch.completed

  if (finished) {
    step.value = 3
  }
}

async function addMembers() {
  const finished = await memberBatch.run(
    chosenParticipants.value,
    (participantId, key) => api.admin.addMember(
      createdYear.value.tippYearId, { participantId }, key
    )
  )

  memberCount.value = memberBatch.completed

  if (finished) {
    step.value = 4
  }
}

async function start() {
  const accepted = await startCmd.run(
    key => api.admin.changeTippYearStatus(
      createdYear.value.tippYearId, { status: 'running' }, key
    )
  )

  if (accepted) {
    emit('finished', createdYear.value.tippYearId)
  }
}
</script>

<style scoped>
.wizard {
  border-top: 3px solid var(--blue);
}

/* --- Step indicator --------------------------------------------------- */

.steps {
  display: flex;
  flex-wrap: wrap;
  gap: 1.5rem;
  list-style: none;
  margin: 0 0 1.5rem;
  padding: 0 0 1.25rem;
  border-bottom: 1px solid var(--gray-300);
}

.steps li {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  color: var(--gray-400);
  font-size: 0.9375rem;
}

.steps li.current {
  color: var(--gray-900);
  font-weight: 600;
}

.steps li.done {
  color: var(--green);
}

.marker {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.5rem;
  height: 1.5rem;
  border-radius: 50%;
  border: 1px solid currentcolor;
  font-size: 0.8125rem;
}

.steps li.current .marker {
  background: var(--blue);
  border-color: var(--blue);
  color: white;
}

/* --- Period templates ------------------------------------------------- */

.templates {
  display: grid;
  gap: 0.5rem;
  margin-bottom: 1.25rem;
}

.template {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.625rem 0.875rem;
  border: 1px solid var(--gray-300);
  border-radius: 8px;
  cursor: pointer;
}

.template.chosen {
  border-color: var(--blue);
  background: var(--gray-50);
}

.template-label {
  flex: 1;
}

.template-count {
  color: var(--gray-600);
  font-size: 0.875rem;
}

.preview {
  max-height: 18rem;
  overflow-y: auto;
  margin-bottom: 1.25rem;
}

/* --- Participants ----------------------------------------------------- */

.people {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 0.5rem;
  margin-bottom: 1.25rem;
}

.person {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem 0.75rem;
  border: 1px solid var(--gray-300);
  border-radius: 8px;
  cursor: pointer;
}

.person-id {
  margin-left: auto;
  color: var(--gray-400);
  font-size: 0.8125rem;
}

/* --- Actions ---------------------------------------------------------- */

.actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  justify-content: flex-end;
  margin-top: 1.25rem;
}
</style>
