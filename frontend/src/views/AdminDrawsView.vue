<template>
  <div class="page-header">
    <div>
      <h1>Ziehungen</h1>
      <p class="subtitle">
        Ziehung eintragen (B-08) und den Gewinn des Tippscheins nachtragen (B-09).
      </p>
    </div>
  </div>

  <div class="card section">
    <div class="field-inline">
      <div class="field">
        <label for="tippYear">Tippjahr</label>
        <select
          id="tippYear"
          v-model="tippYearId"
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
      <button
        class="btn-secondary"
        :disabled="!tippYearId || draws.loading"
        @click="loadDraws"
      >
        Aktualisieren
      </button>
    </div>
    <div
      v-if="years.error"
      class="state error"
    >
      {{ years.error }}
    </div>
  </div>

  <!-- B-08 -->
  <div class="card section">
    <h3>Ziehung eintragen</h3>
    <form @submit.prevent="recordDraw">
      <DrawFields
        v-model:draw-date="newDraw.drawDate"
        v-model:numbers="newDraw.numbers"
        v-model:superzahl="newDraw.superzahl"
        id-prefix="new"
      />

      <!--
        The note that used to stand here explained that a duplicate draw date is
        rejected. If it happens, the error message says so - announcing it in
        advance is a rule nobody can act on while filling the form.
      -->

      <button
        class="btn-primary"
        :disabled="recordCmd.pending || !tippYearId || !isComplete(newDraw)"
        type="submit"
      >
        {{ recordCmd.pending ? 'Wird gesendet …' : 'Ziehung eintragen' }}
      </button>
      <CommandFeedback :command="recordCmd" />
    </form>
  </div>

  <div
    v-if="!tippYearId"
    class="state empty"
  >
    Kein Tippjahr gewählt.
  </div>
  <div
    v-else-if="draws.loading"
    class="state loading"
  >
    Wird geladen …
  </div>
  <div
    v-else-if="draws.error"
    class="state error"
  >
    {{ draws.error }}
  </div>
  <div
    v-else-if="!drawList.length"
    class="state empty"
  >
    Für dieses Tippjahr ist noch keine Ziehung eingetragen.
  </div>

  <template v-else>
    <div
      v-for="draw in drawList"
      :key="draw.drawId"
      class="card"
    >
      <div class="page-header">
        <div>
          <h3>{{ formatDate(draw.drawDate) }}</h3>
          <p class="subtitle">
            Ziehung #{{ draw.drawId }}
          </p>
        </div>
        <div class="header-actions">
          <!--
            B-28: Solange nichts gebucht ist, ist ein Tippfehler ein Tippfehler.
            Danach nicht mehr — dann hängen Gebühren und Jahressumme daran, und
            der Weg zurück ist der Gewinn, nicht die Zahl.
          -->
          <button
            v-if="draw.status !== 'evaluated'"
            class="btn-secondary small"
            type="button"
            @click="toggleEdit(draw)"
          >
            {{ editing[draw.drawId] ? 'Abbrechen' : 'Ändern' }}
          </button>
          <span
            class="badge"
            :class="draw.status"
          >{{ statusLabel(draw.status) }}</span>
        </div>
      </div>

      <div class="numbers section">
        <span
          v-for="number in draw.numbers"
          :key="number"
          class="ball"
        >{{ number }}</span>
        <span
          v-if="draw.superzahl !== null"
          class="ball superzahl"
        >{{ draw.superzahl }}</span>
      </div>

      <!-- B-28 -->
      <form
        v-if="editing[draw.drawId]"
        class="section correction"
        @submit.prevent="correctDraw(draw.drawId)"
      >
        <h4>Ziehung ändern</h4>
        <p class="state note">
          Datum, Zahlen und Superzahl werden so gespeichert, wie sie hier stehen. Die
          Reihen des Scheins werden danach neu ausgewertet — ein geändertes Datum kann
          auch einen anderen Schein betreffen.
        </p>

        <DrawFields
          v-model:draw-date="editing[draw.drawId].drawDate"
          v-model:numbers="editing[draw.drawId].numbers"
          v-model:superzahl="editing[draw.drawId].superzahl"
          :id-prefix="`draw-${draw.drawId}`"
        />

        <button
          class="btn-primary"
          type="submit"
          :disabled="correctCmds[draw.drawId]?.pending || !isComplete(editing[draw.drawId])"
        >
          {{ correctCmds[draw.drawId]?.pending ? 'Wird gesendet …' : 'Änderung speichern' }}
        </button>
        <CommandFeedback
          v-if="correctCmds[draw.drawId]"
          :command="correctCmds[draw.drawId]"
        />
      </form>

      <div
        v-if="draw.ticket"
        class="section"
      >
        <!--
          B-26: Welcher Schein an dieser Ziehung teilgenommen hat, mit seiner
          Losnummer und der Superzahl daraus. Beides gehört zum Schein und nicht
          zur Ziehung — und weil sich Laufzeiten überschneiden dürfen, ist die
          Losnummer die einzige Angabe, an der sich prüfen lässt, ob ausgewertet
          wurde, was in der Hand liegt.
        -->
        <dl class="facts">
          <dt>Tippschein</dt>
          <dd>
            {{ draw.ticket.lotteryReference ?? 'ohne Losnummer' }}
            <span class="muted">
              · #{{ draw.ticket.ticketId }} · {{ draw.ticket.rowCount }} Reihen
            </span>
          </dd>

          <dt>Superzahl des Scheins</dt>
          <dd>
            {{ draw.ticket.superzahl ?? '–' }}
            <span class="muted">
              <template v-if="draw.ticket.superzahl === null">
                nicht erfasst — ohne sie erreicht keine Reihe eine Klasse mit Superzahl
              </template>
              <template v-else-if="draw.ticket.superzahl === draw.superzahl">
                — trifft die gezogene Superzahl
              </template>
              <template v-else>
                — letzte Ziffer der Losnummer, gezogen wurde {{ draw.superzahl }}
              </template>
            </span>
          </dd>

          <template v-if="draw.ticket.totalAmount !== null && draw.ticket.totalAmount !== undefined">
            <dt>Gewinn des Scheins</dt>
            <dd>{{ formatAmount(draw.ticket.totalAmount) }}</dd>
          </template>
        </dl>
      </div>

      <!-- B-24 -->
      <div
        v-if="draw.ticket"
        class="section"
      >
        <h4>Reihen des Scheins</h4>
        <DrawRows
          :rows="draw.ticket.rows ?? []"
          :numbers="draw.numbers ?? []"
        />
      </div>

      <!--
        Ohne teilnehmenden Schein gibt es weder etwas einzutragen noch etwas
        abzuschließen — der Gewinn gehört dem Schein, nicht der Ziehung.
      -->
      <div
        v-if="!draw.ticket"
        class="state empty section"
      >
        An dieser Ziehung hat kein Tippschein teilgenommen. Sobald einer erfasst ist, der
        den {{ formatDate(draw.drawDate) }} abdeckt, lässt sich der Gewinn nachtragen.
      </div>

      <!-- B-09, B-23, B-27 -->
      <WinningsEntry
        v-else
        :draw-id="draw.drawId"
        :winning-classes="draw.ticket.winningClasses ?? []"
        :status="draw.status"
        :pending="winningsCmds[draw.drawId]?.pending ?? false"
        @submit="payload => recordWinnings(draw.drawId, payload)"
      />

      <CommandFeedback
        v-if="winningsCmds[draw.drawId]"
        :command="winningsCmds[draw.drawId]"
      />
    </div>
  </template>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import CommandFeedback from '@/components/CommandFeedback.vue'
import DrawFields from '@/components/DrawFields.vue'
import DrawRows from '@/components/DrawRows.vue'
import WinningsEntry from '@/components/WinningsEntry.vue'
import api from '@/services/api'
import { useCommand, useQuery } from '@/composables/useCommand'
import { formatAmount, formatDate, statusLabel } from '@/support/format'

const years = useQuery()
const draws = useQuery()
const recordCmd = useCommand()

const tippYearId = ref('')
const winningsCmds = reactive({})
const correctCmds = reactive({})

/** Per draw the values being edited, or nothing while it is not (B-28). */
const editing = reactive({})

const tippYears = computed(() => years.data?.tippYears ?? [])
const drawList = computed(() => draws.data?.draws ?? [])

const newDraw = reactive({ drawDate: '', numbers: [], superzahl: null })

/**
 * One command state per draw, not one for the page.
 *
 * They must not share an idempotency key: a key held over from a draw whose
 * request never came back would, on the next row's submit, replay that first
 * draw's answer instead of booking this one.
 */
function commandFor(drawId) {
  return (winningsCmds[drawId] ??= useCommand())
}

// Created before the rows render, not while they render - reaching into a
// reactive map from a template would mutate state mid-render.
watch(drawList, list => list.forEach(draw => commandFor(draw.drawId)))

function loadDraws() {
  if (tippYearId.value) {
    draws.load(() => api.getDraws(tippYearId.value))
  }
}

// Choosing a year loads it; the button beside the dropdown is for fetching the
// same year again, which is a different intention and keeps its own word.
watch(tippYearId, loadDraws)

/** A day, six numbers and a Superzahl - the same three for entry and correction. */
const isComplete = draw =>
  Boolean(draw?.drawDate) && draw?.numbers?.length === 6 && draw?.superzahl !== null

/**
 * B-28: opens the correction with what is on screen, closes it without saving.
 *
 * The values are copied out of the draw rather than bound to it - the list is
 * reloaded after every command, and editing the row in place would have the
 * fields jump back mid-typing.
 */
function toggleEdit(draw) {
  if (editing[draw.drawId]) {
    delete editing[draw.drawId]

    return
  }

  editing[draw.drawId] = {
    drawDate: draw.drawDate,
    numbers: [...(draw.numbers ?? [])],
    superzahl: draw.superzahl
  }
}

async function correctDraw(drawId) {
  const values = editing[drawId]

  if (!values || !isComplete(values)) {
    return
  }

  const command = (correctCmds[drawId] ??= useCommand())

  const accepted = await command.run(key => api.admin.correctDraw(drawId, {
    drawDate: values.drawDate,
    numbers: [...values.numbers],
    superzahl: values.superzahl
  }, key))

  if (accepted) {
    delete editing[drawId]
    loadDraws()
  }
}

async function recordDraw() {
  // Nothing to parse or check about the numbers here: the grid hands over six
  // distinct numbers from 1 to 49, ascending, or the submit button is not
  // enabled at all.
  const accepted = await recordCmd.run(key => api.admin.recordDraw({
    tippYearId: Number(tippYearId.value),
    drawDate: newDraw.drawDate,
    numbers: [...newDraw.numbers],
    superzahl: newDraw.superzahl
  }, key))

  if (accepted) {
    newDraw.drawDate = ''
    newDraw.numbers = []
    newDraw.superzahl = null
    loadDraws()
  }
}

/**
 * The payload comes from WinningsEntry and is already one of the two shapes
 * B-23 allows - a total, or the amounts per class. It is passed on unchanged:
 * deciding here which of them applies would be the same rule in a second
 * place, and the API would still have the last word.
 */
async function recordWinnings(drawId, payload) {
  const accepted = await commandFor(drawId).run(
    key => api.admin.recordDrawWinnings(drawId, payload, key)
  )

  if (accepted) {
    loadDraws()
  }
}

onMounted(() => years.load(() => api.admin.getTippYears()))
</script>

<style scoped>
/* Beisatz zu einer Angabe, nicht die Angabe selbst */
.muted {
  color: var(--gray-500);
  font-weight: 400;
  font-size: 0.8125rem;
}

.header-actions {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.btn-secondary.small {
  padding: 0.25rem 0.625rem;
  font-size: 0.8125rem;
}

/* Abgesetzt, weil hier bestehende Daten überschrieben werden */
.correction {
  border-left: 3px solid var(--gray-300);
  padding-left: 1rem;
}
</style>
