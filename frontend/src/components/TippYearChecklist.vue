<template>
  <div class="card checklist">
    <div class="page-header">
      <div>
        <h3>{{ year.name }}</h3>
        <p class="subtitle">
          #{{ year.tippYearId }} · {{ formatDate(year.startDate) }} –
          {{ formatDate(year.endDate) }} · {{ statusLabel(year.status) }}
        </p>
      </div>
    </div>

    <ol class="items">
      <!-- 1 — the year itself exists, so this one is always settled -->
      <li class="item done">
        <span class="mark">✓</span>
        <div class="body">
          <p class="title">
            Eckdaten festgelegt
          </p>
          <p class="detail">
            {{ formatAmount(year.ticketCostPerRow) }} je Reihe und Ziehung
          </p>
        </div>
      </li>

      <!-- 2 — B-14 -->
      <li
        class="item"
        :class="periods.length ? 'done' : 'todo'"
      >
        <span class="mark">{{ periods.length ? '✓' : '!' }}</span>
        <div class="body">
          <p class="title">
            {{ periods.length ? `${periods.length} Tippperioden` : 'Noch keine Tippperiode' }}
          </p>
          <p class="detail">
            <template v-if="periods.length">
              {{ coverage }}
            </template>
            <template v-else>
              Ohne Periode kann keine Tippreihe zugeordnet werden.
            </template>
          </p>

          <button
            class="btn-link"
            type="button"
            @click="open = open === 'periods' ? null : 'periods'"
          >
            {{ open === 'periods' ? 'schließen' : 'Periode hinzufügen' }}
          </button>

          <form
            v-if="open === 'periods'"
            class="inline-form"
            @submit.prevent="createPeriod"
          >
            <div class="field-row">
              <div class="field">
                <label :for="`cl-name-${year.tippYearId}`">Name</label>
                <input
                  :id="`cl-name-${year.tippYearId}`"
                  v-model="newPeriod.name"
                  required
                >
              </div>
              <div class="field">
                <label :for="`cl-start-${year.tippYearId}`">Beginn</label>
                <input
                  :id="`cl-start-${year.tippYearId}`"
                  v-model="newPeriod.startDate"
                  type="date"
                  :min="year.startDate"
                  :max="year.endDate"
                  required
                >
              </div>
              <div class="field">
                <label :for="`cl-end-${year.tippYearId}`">Ende</label>
                <input
                  :id="`cl-end-${year.tippYearId}`"
                  v-model="newPeriod.endDate"
                  type="date"
                  :min="year.startDate"
                  :max="year.endDate"
                  required
                >
              </div>
            </div>

            <!--
              The same three rules the API enforces, checked here so the reason
              is readable and sits next to the field instead of arriving as a
              409. Convenience only - the aggregate still decides.
            -->
            <p
              v-if="periodProblem"
              class="state error"
            >
              {{ periodProblem }}
            </p>

            <button
              class="btn-primary"
              :disabled="!!periodProblem || periodCmd.pending"
              type="submit"
            >
              {{ periodCmd.pending ? 'Wird gesendet …' : 'Periode anlegen' }}
            </button>
            <CommandFeedback :command="periodCmd" />
          </form>
        </div>
      </li>

      <!-- 3 — B-11 -->
      <li
        class="item"
        :class="year.memberCount ? 'done' : 'todo'"
      >
        <span class="mark">{{ year.memberCount ? '✓' : '!' }}</span>
        <div class="body">
          <p class="title">
            {{ year.memberCount ? `${year.memberCount} Teilnehmer` : 'Noch kein Teilnehmer' }}
          </p>
          <p class="detail">
            Nur aufgenommene Teilnehmer bekommen eine Reihe und eine Gebühr.
          </p>

          <button
            class="btn-link"
            type="button"
            @click="open = open === 'members' ? null : 'members'"
          >
            {{ open === 'members' ? 'schließen' : 'Teilnehmer aufnehmen' }}
          </button>

          <div
            v-if="open === 'members'"
            class="inline-form"
          >
            <div class="people">
              <label
                v-for="participant in participants"
                :key="participant.participantId"
                class="person"
              >
                <input
                  v-model="chosen"
                  type="checkbox"
                  :value="participant.participantId"
                >
                {{ participant.displayName }}
              </label>
            </div>

            <p class="state note">
              Wer bereits aufgenommen ist, wird abgelehnt — eine Teilnahme je Person und
              Tippjahr.
            </p>

            <p
              v-if="memberBatch.pending"
              class="state loading"
            >
              {{ memberBatch.completed + 1 }} von {{ memberBatch.total }} …
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

            <button
              class="btn-primary"
              :disabled="!chosen.length || memberBatch.pending"
              type="button"
              @click="addMembers"
            >
              {{ chosen.length }} aufnehmen
            </button>
          </div>
        </div>
      </li>

      <!-- 4 — B-18 -->
      <li
        class="item"
        :class="year.status === 'running' ? 'done' : 'todo'"
      >
        <span class="mark">{{ year.status === 'running' ? '✓' : '!' }}</span>
        <div class="body">
          <p class="title">
            {{ year.status === 'running' ? 'Läuft' : `Status: ${statusLabel(year.status)}` }}
          </p>
          <p class="detail">
            <template v-if="year.status === 'running'">
              Nimmt Tippscheine an.
            </template>
            <template v-else-if="blockedBy">
              {{ blockedBy.name }} läuft bereits — es darf immer nur ein Tippjahr laufen.
            </template>
            <template v-else>
              Erst ein laufendes Tippjahr nimmt Tippscheine an.
            </template>
          </p>

          <button
            v-if="year.status !== 'running'"
            class="btn-primary"
            :disabled="!!blockedBy || startCmd.pending"
            type="button"
            @click="start"
          >
            {{ startCmd.pending ? 'Wird gestartet …' : 'Tippjahr starten' }}
          </button>
          <CommandFeedback :command="startCmd" />
        </div>
      </li>
    </ol>
  </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import CommandFeedback from '@/components/CommandFeedback.vue'
import api from '@/services/api'
import { useBatch, useCommand } from '@/composables/useCommand'
import { rejectionReason, suggestedStart } from '@/support/betPeriods'
import { formatAmount, formatDate, statusLabel } from '@/support/format'

const props = defineProps({
  year: { type: Object, required: true },
  periods: { type: Array, default: () => [] },
  participants: { type: Array, default: () => [] },
  runningYear: { type: Object, default: null }
})

const emit = defineEmits(['changed'])

const open = ref(null)
const chosen = ref([])
const newPeriod = reactive({ name: '', startDate: '', endDate: '' })

const periodCmd = useCommand()
const startCmd = useCommand()
const memberBatch = useBatch()

// Another running year blocks this one. Named here only to explain the
// disabled button - the unique key on tipp_year.running_marker decides.
const blockedBy = computed(() =>
  props.runningYear && props.runningYear.tippYearId !== props.year.tippYearId
    ? props.runningYear
    : null
)

const coverage = computed(() => {
  const first = props.periods.map(p => p.startDate).sort()[0]
  const last = props.periods.map(p => p.endDate).sort().at(-1)
  const complete = first === props.year.startDate && last === props.year.endDate

  return complete
    ? 'Decken das Tippjahr lückenlos ab.'
    : `Belegt: ${formatDate(first)} – ${formatDate(last)}.`
})

const periodProblem = computed(() =>
  rejectionReason(newPeriod, props.year, props.periods)
)

// Prefill the next period so the dates do not have to be worked out by hand.
watch(() => open.value, value => {
  if (value === 'periods' && !newPeriod.startDate) {
    newPeriod.startDate = suggestedStart(props.year, props.periods)
  }
})

async function createPeriod() {
  const accepted = await periodCmd.run(key => api.admin.createBetPeriod(
    props.year.tippYearId,
    { name: newPeriod.name, startDate: newPeriod.startDate, endDate: newPeriod.endDate },
    key
  ))

  if (accepted) {
    newPeriod.name = ''
    newPeriod.endDate = ''
    newPeriod.startDate = ''
    emit('changed')
  }
}

async function addMembers() {
  const finished = await memberBatch.run(
    chosen.value,
    (participantId, key) => api.admin.addMember(props.year.tippYearId, { participantId }, key)
  )

  emit('changed')

  if (finished) {
    chosen.value = []
    memberBatch.reset()
    open.value = null
  }
}

async function start() {
  const accepted = await startCmd.run(key => api.admin.changeTippYearStatus(
    props.year.tippYearId, { status: 'running' }, key
  ))

  if (accepted) {
    emit('changed')
  }
}
</script>

<style scoped>
.checklist {
  border-top: 3px solid var(--gray-300);
}

.items {
  list-style: none;
  margin: 0;
  padding: 0;
}

.item {
  display: flex;
  gap: 0.875rem;
  padding: 1rem 0;
  border-top: 1px solid var(--gray-100);
}

.item:first-child {
  border-top: none;
}

.mark {
  flex: 0 0 1.5rem;
  height: 1.5rem;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 0.8125rem;
  font-weight: 600;
  color: white;
}

.item.done .mark {
  background: var(--green);
}

.item.todo .mark {
  background: var(--yellow);
}

.body {
  flex: 1;
  min-width: 0;
}

.title {
  color: var(--gray-900);
  font-weight: 600;
}

.detail {
  color: var(--gray-600);
  font-size: 0.875rem;
  margin-top: 0.125rem;
}

.body > .btn-link {
  margin-top: 0.5rem;
}

.inline-form {
  margin-top: 0.875rem;
  padding-top: 0.875rem;
  border-top: 1px dashed var(--gray-300);
}

.people {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 0.5rem;
  margin-bottom: 0.75rem;
}

.person {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.4375rem 0.625rem;
  border: 1px solid var(--gray-300);
  border-radius: 8px;
  cursor: pointer;
  font-size: 0.9375rem;
}
</style>
