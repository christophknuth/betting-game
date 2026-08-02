<template>
  <div class="page-header">
    <div>
      <h1>Ziehungen</h1>
      <p class="subtitle">
        Was der <em>gesamte</em> Tippschein gewonnen hat — nicht der eigene Anteil.
        Den gibt es erst mit der Jahresausschüttung.
      </p>
    </div>
  </div>

  <div class="card section">
    <div class="field-inline">
      <TippYearPicker v-model="tippYearId" />

      <div class="field">
        <label for="status">Status</label>
        <select
          id="status"
          v-model="filters.status"
        >
          <option value="">
            alle
          </option>
          <option value="scheduled">
            angesetzt
          </option>
          <option value="drawn">
            gezogen
          </option>
          <option value="evaluated">
            ausgewertet
          </option>
        </select>
      </div>

      <label class="checkbox">
        <input
          v-model="filters.withWinningsOnly"
          type="checkbox"
        >
        nur Ziehungen mit Gewinn
      </label>
    </div>
  </div>

  <div
    v-if="!tippYearId"
    class="state empty"
  >
    Kein Tippjahr gewählt. Die Auswahl stammt aus den eigenen Teilnahmen — ohne Teilnahme
    gibt es hier nichts zu zeigen.
  </div>

  <div
    v-else-if="query.loading"
    class="state loading"
  >
    Wird geladen …
  </div>
  <div
    v-else-if="query.error"
    class="state error"
  >
    {{ query.error }}
  </div>

  <template v-else-if="query.data">
    <div class="card section">
      <h3>Gewinne des Tippjahres</h3>
      <p class="figure">
        {{ formatAmount(query.data.totalWinnings) }}
      </p>
      <p class="subtitle">
        Immer die volle Jahressumme, unabhängig vom Filter oben — eine gefilterte Liste
        soll nicht wie ein kleineres Jahr aussehen.
      </p>
    </div>

    <div
      v-if="!query.data.draws.length"
      class="state empty"
    >
      Zu diesem Filter gibt es keine Ziehungen.
    </div>

    <div
      v-for="draw in query.data.draws"
      :key="draw.drawId"
      class="card"
    >
      <div class="page-header">
        <div>
          <!-- The date is what identifies a draw to the people playing it -->
          <h3>Ziehung vom {{ formatDate(draw.drawDate) }}</h3>
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
          title="Superzahl"
        >
          {{ draw.superzahl }}
        </span>
      </div>

      <div
        v-if="!draw.ticket"
        class="state empty"
      >
        An dieser Ziehung hat kein Tippschein teilgenommen.
      </div>

      <template v-else>
        <dl class="facts section">
          <dt>Tippschein</dt>
          <dd>{{ draw.ticket.rowCount }} Reihen</dd>

          <dt>Gewinn des Scheins</dt>
          <dd>{{ formatAmount(draw.ticket.totalAmount) }}</dd>

          <template v-if="draw.ticket.bestMatch">
            <dt>Beste Reihe</dt>
            <dd>{{ bestMatchLabel(draw.ticket.bestMatch) }}</dd>
          </template>
        </dl>

        <!-- B-24 -->
        <div class="section">
          <h4>Reihen des Scheins</h4>
          <DrawRows
            :rows="draw.ticket.rows ?? []"
            :numbers="draw.numbers ?? []"
          />
        </div>

        <div
          v-if="draw.ticket.winningClasses?.length"
          class="table-wrap"
        >
          <table class="data">
            <thead>
              <tr>
                <th scope="col">
                  Gewinnklasse
                </th>
                <th
                  scope="col"
                  class="numeric"
                >
                  Reihen
                </th>
                <th
                  scope="col"
                  class="numeric"
                >
                  Betrag
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="entry in draw.ticket.winningClasses"
                :key="entry.winningClass"
              >
                <td>{{ winningClassLabel(entry.winningClass) }}</td>
                <td class="numeric">
                  {{ entry.rowCount }}
                </td>
                <td class="numeric">
                  {{ formatAmount(entry.amount) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </div>
  </template>
</template>

<script setup>
import { reactive, ref, watch } from 'vue'
import DrawRows from '@/components/DrawRows.vue'
import TippYearPicker from '@/components/TippYearPicker.vue'
import api from '@/services/api'
import { useQuery } from '@/composables/useCommand'
import { formatAmount, formatDate, statusLabel, winningClassLabel } from '@/support/format'

const query = useQuery()

const tippYearId = ref('')
const filters = reactive({ status: '', withWinningsOnly: false })

const reload = () => {
  if (tippYearId.value) {
    query.load(() => api.getDraws(tippYearId.value, { ...filters }))
  }
}

/**
 * "5 Richtige" or "5 Richtige + Superzahl".
 *
 * Assembled here rather than from two template fragments: as markup, the space
 * before the "+" depended on where the line happened to break, and a formatter
 * is free to break it elsewhere.
 */
const bestMatchLabel = (bestMatch) =>
  `${bestMatch.matchedNumbers} Richtige${bestMatch.superzahlMatched ? ' + Superzahl' : ''}`

// Nothing is loaded on mount: TippYearPicker preselects the newest year once
// the memberships are in, and this watcher turns that into the first request.
// The two filters below it load the same way - all three are dropdowns, and a
// dropdown that needs a button pressed afterwards is a dropdown twice.
watch([tippYearId, filters], reload)
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
