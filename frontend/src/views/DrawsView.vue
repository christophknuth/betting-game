<template>
  <div class="page-header">
    <div>
      <h2>Ziehungen</h2>
      <p class="subtitle">
        Was der <em>gesamte</em> Tippschein gewonnen hat — nicht der eigene Anteil.
        Den gibt es erst mit der Jahresausschüttung.
      </p>
    </div>
  </div>

  <div class="card section">
    <div class="field-inline">
      <div class="field">
        <label for="tippYearId">Tippjahr</label>
        <select v-if="tippYears.length" id="tippYearId" v-model="tippYearId">
          <option v-for="year in tippYears" :key="year.tippYearId" :value="year.tippYearId">
            {{ year.tippYearName }} (#{{ year.tippYearId }})
          </option>
        </select>
        <input v-else id="tippYearId" v-model="tippYearId" type="number" min="1" placeholder="ID des Tippjahres">
      </div>

      <div class="field">
        <label for="status">Status</label>
        <select id="status" v-model="filters.status">
          <option value="">alle</option>
          <option value="scheduled">angesetzt</option>
          <option value="drawn">gezogen</option>
          <option value="evaluated">ausgewertet</option>
        </select>
      </div>

      <label class="checkbox">
        <input v-model="filters.withWinningsOnly" type="checkbox">
        nur Ziehungen mit Gewinn
      </label>

      <button class="btn-primary" :disabled="query.loading || !tippYearId" @click="reload">
        Anzeigen
      </button>
    </div>
  </div>

  <div v-if="!tippYearId" class="state empty">
    Kein Tippjahr gewählt. Die Auswahl stammt aus den eigenen Teilnahmen; ohne Teilnahme
    lässt sich die ID direkt eintragen.
  </div>

  <div v-else-if="query.loading" class="state loading">Wird geladen …</div>
  <div v-else-if="query.error" class="state error">{{ query.error }}</div>

  <template v-else-if="query.data">
    <div class="card section">
      <h3>Gewinne des Tippjahres</h3>
      <p class="figure">{{ formatAmount(query.data.totalWinnings) }}</p>
      <p class="subtitle">
        Immer die volle Jahressumme, unabhängig vom Filter oben — eine gefilterte Liste
        soll nicht wie ein kleineres Jahr aussehen.
      </p>
    </div>

    <div v-if="!query.data.draws.length" class="state empty">
      Zu diesem Filter gibt es keine Ziehungen.
    </div>

    <div v-for="draw in query.data.draws" :key="draw.drawId" class="card">
      <div class="page-header">
        <div>
          <h3>Ziehung vom {{ formatDate(draw.drawDate) }}</h3>
          <p class="subtitle">#{{ draw.drawId }}</p>
        </div>
        <span class="badge" :class="draw.status">{{ statusLabel(draw.status) }}</span>
      </div>

      <div class="numbers section">
        <span v-for="number in draw.numbers" :key="number" class="ball">{{ number }}</span>
        <span v-if="draw.superzahl !== null" class="ball superzahl" title="Superzahl">
          {{ draw.superzahl }}
        </span>
      </div>

      <div v-if="!draw.ticket" class="state empty">
        An dieser Ziehung hat kein Tippschein teilgenommen.
      </div>

      <template v-else>
        <dl class="facts section">
          <dt>Tippschein</dt>
          <dd>#{{ draw.ticket.ticketId }} mit {{ draw.ticket.rowCount }} Reihen</dd>

          <dt>Gewinn des Scheins</dt>
          <dd>{{ formatAmount(draw.ticket.totalAmount) }}</dd>

          <template v-if="draw.ticket.bestMatch">
            <dt>Beste Reihe</dt>
            <dd>
              {{ draw.ticket.bestMatch.matchedNumbers }} Richtige<template
                v-if="draw.ticket.bestMatch.superzahlMatched"
              > + Superzahl</template>
            </dd>
          </template>
        </dl>

        <div v-if="draw.ticket.winningClasses?.length" class="table-wrap">
          <table class="data">
            <thead>
              <tr>
                <th>Gewinnklasse</th>
                <th class="numeric">Reihen</th>
                <th class="numeric">Betrag</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="entry in draw.ticket.winningClasses" :key="entry.winningClass">
                <td>{{ winningClassLabel(entry.winningClass) }}</td>
                <td class="numeric">{{ entry.rowCount }}</td>
                <td class="numeric">{{ formatAmount(entry.amount) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </div>
  </template>
</template>

<script setup>
import { onMounted, reactive, ref, watch } from 'vue'
import api from '@/services/api'
import { useQuery } from '@/composables/useCommand'
import { useAuthStore } from '@/stores/auth'
import { formatAmount, formatDate, statusLabel, winningClassLabel } from '@/support/format'

const authStore = useAuthStore()
const query = useQuery()

const tippYearId = ref('')
const tippYears = ref([])
const filters = reactive({ status: '', withWinningsOnly: false })

const reload = () => {
  if (tippYearId.value) {
    query.load(() => api.getDraws(tippYearId.value, { ...filters }))
  }
}

// Loading is left to the watcher, including for the initial selection below -
// calling it here as well would fire the same request twice.
watch(tippYearId, reload)

// The tipp years to choose from come from the caller's own memberships. There
// is no participant-facing endpoint that lists tipp years - only the admin has
// one - and asking a member which years they played is the same question.
onMounted(async () => {
  if (!authStore.participantId) {
    return
  }

  try {
    const { data: own } = await api.getMemberships(authStore.participantId)
    tippYears.value = own.memberships ?? []
    tippYearId.value = tippYears.value[tippYears.value.length - 1]?.tippYearId ?? ''
  } catch {
    tippYears.value = []
  }
})
</script>

<style scoped>
.figure {
  font-size: 1.75rem;
  font-weight: 700;
  color: var(--green);
  font-variant-numeric: tabular-nums;
  margin-bottom: 0.5rem;
}
</style>
