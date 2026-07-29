<template>
  <div class="page-header">
    <div>
      <h2>Ziehungen</h2>
      <p class="subtitle">
        Ziehung eintragen (B-08) und den Gewinn des Tippscheins nachtragen (B-09).
      </p>
    </div>
  </div>

  <div class="card section">
    <div class="field-inline">
      <div class="field">
        <label for="tippYear">Tippjahr</label>
        <select id="tippYear" v-model="tippYearId" @change="loadDraws">
          <option value="">bitte wählen</option>
          <option v-for="year in tippYears" :key="year.tippYearId" :value="year.tippYearId">
            {{ year.name }} (#{{ year.tippYearId }})
          </option>
        </select>
      </div>
      <button class="btn-secondary" :disabled="!tippYearId || draws.loading" @click="loadDraws">
        Aktualisieren
      </button>
    </div>
    <div v-if="years.error" class="state error">{{ years.error }}</div>
  </div>

  <!-- B-08 -->
  <div class="card section">
    <h3>Ziehung eintragen</h3>
    <form @submit.prevent="recordDraw">
      <div class="field-row">
        <div class="field">
          <label for="drawDate">Ziehungsdatum</label>
          <input id="drawDate" v-model="newDraw.drawDate" type="date" required>
        </div>
        <div class="field">
          <label for="drawNumbers">Gewinnzahlen</label>
          <input id="drawNumbers" v-model="newDraw.numbers" placeholder="3 12 19 27 33 45" required>
        </div>
        <div class="field">
          <label for="superzahl">Superzahl</label>
          <input id="superzahl" v-model="newDraw.superzahl" type="number" min="0" max="9" required>
        </div>
      </div>

      <p class="state note">Ein doppeltes Ziehungsdatum wird mit 409 abgelehnt.</p>
      <div v-if="numbersError" class="state error">{{ numbersError }}</div>

      <button class="btn-primary" :disabled="recordCmd.pending || !tippYearId" type="submit">
        {{ recordCmd.pending ? 'Wird gesendet …' : 'Ziehung eintragen' }}
      </button>
      <CommandFeedback :command="recordCmd" />
    </form>
  </div>

  <div v-if="!tippYearId" class="state empty">Kein Tippjahr gewählt.</div>
  <div v-else-if="draws.loading" class="state loading">Wird geladen …</div>
  <div v-else-if="draws.error" class="state error">{{ draws.error }}</div>
  <div v-else-if="!drawList.length" class="state empty">
    Für dieses Tippjahr ist noch keine Ziehung eingetragen.
  </div>

  <template v-else>
    <div v-for="draw in drawList" :key="draw.drawId" class="card">
      <div class="page-header">
        <div>
          <h3>{{ formatDate(draw.drawDate) }}</h3>
          <p class="subtitle">Ziehung #{{ draw.drawId }}</p>
        </div>
        <span class="badge" :class="draw.status">{{ statusLabel(draw.status) }}</span>
      </div>

      <div class="numbers section">
        <span v-for="number in draw.numbers" :key="number" class="ball">{{ number }}</span>
        <span v-if="draw.superzahl !== null" class="ball superzahl">{{ draw.superzahl }}</span>
      </div>

      <div v-if="draw.ticket" class="state success section">
        Tippschein #{{ draw.ticket.ticketId }} ({{ draw.ticket.rowCount }} Reihen) hat
        {{ formatAmount(draw.ticket.totalAmount) }} gewonnen.
      </div>

      <!-- B-09 -->
      <form @submit.prevent="recordWinnings(draw.drawId)">
        <div class="field">
          <label :for="`amount-${draw.drawId}`">Gewinn des gesamten Scheins</label>
          <input
            :id="`amount-${draw.drawId}`"
            v-model="amounts[draw.drawId]"
            type="number"
            step="0.01"
            min="0"
            required
          >
          <span class="hint">
            Ohne Aufschlüsselung rechnet das System die Treffer je Reihe selbst aus den
            Reihen-Snapshots des Scheins.
          </span>
        </div>

        <button class="btn-primary" :disabled="winningsCmds[draw.drawId]?.pending" type="submit">
          {{ winningsCmds[draw.drawId]?.pending ? 'Wird gesendet …' : 'Gewinn eintragen' }}
        </button>

        <CommandFeedback v-if="winningsCmds[draw.drawId]" :command="winningsCmds[draw.drawId]" />
      </form>
    </div>
  </template>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import CommandFeedback from '@/components/CommandFeedback.vue'
import api from '@/services/api'
import { useCommand, useQuery } from '@/composables/useCommand'
import { formatAmount, formatDate, parseNumbers, statusLabel } from '@/support/format'

const years = useQuery()
const draws = useQuery()
const recordCmd = useCommand()

const tippYearId = ref('')
const numbersError = ref(null)
const amounts = reactive({})
const winningsCmds = reactive({})

const tippYears = computed(() => years.data?.tippYears ?? [])
const drawList = computed(() => draws.data?.draws ?? [])

const newDraw = reactive({ drawDate: '', numbers: '', superzahl: '' })

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

async function recordDraw() {
  // Checked here only to save the round trip; LottoNumbers enforces the same
  // rule in the domain and would answer 400.
  const { numbers, error } = parseNumbers(newDraw.numbers)
  numbersError.value = error

  if (error) {
    return
  }

  const accepted = await recordCmd.run(key => api.admin.recordDraw({
    tippYearId: Number(tippYearId.value),
    drawDate: newDraw.drawDate,
    numbers,
    superzahl: Number(newDraw.superzahl)
  }, key))

  if (accepted) {
    newDraw.drawDate = ''
    newDraw.numbers = ''
    newDraw.superzahl = ''
    loadDraws()
  }
}

async function recordWinnings(drawId) {
  const accepted = await commandFor(drawId).run(
    key => api.admin.recordDrawWinnings(drawId, { totalAmount: Number(amounts[drawId]) }, key)
  )

  if (accepted) {
    loadDraws()
  }
}

onMounted(() => years.load(() => api.admin.getTippYears()))
</script>
