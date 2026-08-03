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
      <div class="field">
        <label for="drawDate">Ziehungsdatum</label>
        <input
          id="drawDate"
          v-model="newDraw.drawDate"
          type="date"
          required
        >
      </div>

      <div class="field">
        <span class="label">Gewinnzahlen</span>
        <NumberGrid v-model="newDraw.numbers" />
      </div>

      <div class="field">
        <span class="label">Superzahl</span>
        <SuperzahlPicker v-model="newDraw.superzahl" />
      </div>

      <!--
        The note that used to stand here explained that a duplicate draw date is
        rejected. If it happens, the error message says so - announcing it in
        advance is a rule nobody can act on while filling the form.
      -->

      <button
        class="btn-primary"
        :disabled="recordCmd.pending || !tippYearId || newDraw.numbers.length !== 6 || newDraw.superzahl === null"
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
        <span
          class="badge"
          :class="draw.status"
        >{{ statusLabel(draw.status) }}</span>
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

      <!-- B-09, B-23 -->
      <WinningsEntry
        :draw-id="draw.drawId"
        :winning-classes="draw.ticket?.winningClasses ?? []"
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
import DrawRows from '@/components/DrawRows.vue'
import NumberGrid from '@/components/NumberGrid.vue'
import SuperzahlPicker from '@/components/SuperzahlPicker.vue'
import WinningsEntry from '@/components/WinningsEntry.vue'
import api from '@/services/api'
import { useCommand, useQuery } from '@/composables/useCommand'
import { formatAmount, formatDate, statusLabel } from '@/support/format'

const years = useQuery()
const draws = useQuery()
const recordCmd = useCommand()

const tippYearId = ref('')
const winningsCmds = reactive({})

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
</style>
