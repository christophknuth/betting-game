<template>
  <div class="page-header">
    <div>
      <h1>Tippjahre</h1>
      <p class="subtitle">
        Einrichten, betreiben und am Jahresende ausschütten.
      </p>
    </div>
    <div class="header-actions">
      <button
        class="btn-secondary"
        :disabled="years.loading"
        @click="loadYears"
      >
        Aktualisieren
      </button>
      <button
        v-if="!wizardOpen"
        class="btn-primary"
        @click="openWizard"
      >
        Neues Tippjahr
      </button>
    </div>
  </div>

  <!-- B-10, B-14, B-11, B-18 in the order they have to happen -->
  <TippYearSetupWizard
    v-if="wizardOpen"
    :participants="participants"
    :running-year="runningYear"
    @finished="finishWizard"
    @cancel="wizardOpen = false"
  />

  <!--
    Only while there is nothing to show. A reload with the list already on
    screen used to replace it with this box: the page lost its height, the
    browser had nowhere to keep the scroll position, and every status change
    dropped the reader back at the top. A refresh dims the table instead.
  -->
  <div
    v-if="years.loading && !tippYears.length"
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

  <template v-else>
    <div
      v-if="!tippYears.length && !wizardOpen"
      class="state empty"
    >
      Noch kein Tippjahr angelegt. <strong>Neues Tippjahr</strong> führt durch die
      Einrichtung.
    </div>

    <template v-else-if="tippYears.length">
      <!--
        Ein Tippjahr läuft, eins ist geplant, der Rest ist Geschichte - aber die
        Liste wächst mit jedem Jahr weiter. Der Filter trennt danach, ob noch
        etwas zu tun ist: ausgeschüttet ist erledigt, alles davor nicht.
      -->
      <div
        class="filters section"
        role="group"
        aria-label="Tippjahre filtern"
      >
        <button
          v-for="option in FILTERS"
          :key="option.value"
          type="button"
          class="filter"
          :class="{ active: filter === option.value }"
          :aria-pressed="filter === option.value"
          @click="filter = option.value"
        >
          {{ option.label }}
          <span class="count">{{ counted(option.value) }}</span>
        </button>
      </div>

      <div
        v-if="!visibleYears.length"
        class="state empty"
      >
        {{ filter === 'current' ? 'Kein Tippjahr, das noch etwas braucht.' : 'Noch kein Jahr ausgeschüttet.' }}
        <button
          class="btn-link"
          type="button"
          @click="filter = 'all'"
        >
          Alle anzeigen
        </button>
      </div>

      <div
        v-else
        ref="yearTable"
        class="section card table-wrap"
        :class="{ refreshing: years.loading }"
      >
        <table class="data">
          <thead>
            <tr>
              <th scope="col">
                ID
              </th>
              <th scope="col">
                Name
              </th>
              <th scope="col">
                Zeitraum
              </th>
              <th scope="col">
                Status
              </th>
              <th
                scope="col"
                class="numeric"
              >
                Reihenpreis
              </th>
              <th
                scope="col"
                class="numeric"
              >
                Mitglieder
              </th>
              <th
                scope="col"
                class="numeric"
              >
                Scheine
              </th>
              <th
                scope="col"
                class="numeric"
              >
                Ziehungen
              </th>
              <th
                scope="col"
                class="numeric"
              >
                Gewinne
              </th>
              <th scope="col" />
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="year in visibleYears"
              :key="year.tippYearId"
              :data-year="year.tippYearId"
              :class="{ 'just-changed': changedId === year.tippYearId }"
            >
              <td>#{{ year.tippYearId }}</td>
              <td>{{ year.name }}</td>
              <td>{{ formatDate(year.startDate) }} – {{ formatDate(year.endDate) }}</td>
              <td>
                <TippYearStatusSelect
                  :year="year"
                  @changed="statusChanged"
                />
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
                <!-- Ein Link, keine Schaltfläche: das Jahr hat eine eigene
                     Adresse, die sich merken und teilen lässt. -->
                <RouterLink
                  class="btn-link"
                  :to="{ name: 'AdminTippYear', params: { tippYearId: year.tippYearId } }"
                >
                  öffnen
                </RouterLink>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <p class="state note">
        <strong>Laufen darf immer nur ein Tippjahr.</strong> Nur ein laufendes nimmt
        Tippscheine an, ausgeschüttet wird nur aus einem abgeschlossenen. Der Status lässt
        sich jederzeit auch zurücksetzen. <strong>Aktuell</strong> zeigt alles, was noch
        etwas braucht — auch ein abgeschlossenes Jahr, solange die Ausschüttung fehlt.
      </p>
    </template>
  </template>
</template>

<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, useTemplateRef } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import TippYearSetupWizard from '@/components/TippYearSetupWizard.vue'
import TippYearStatusSelect from '@/components/TippYearStatusSelect.vue'
import api from '@/services/api'
import { useQuery } from '@/composables/useCommand'
import { formatAmount, formatDate } from '@/support/format'

const router = useRouter()

const years = useQuery()
const people = useQuery()

const wizardOpen = ref(false)

const tippYears = computed(() => years.data?.tippYears ?? [])
const participants = computed(() => people.data?.participants ?? [])

// B-18 allows exactly one running year at a time; the wizard uses this to say
// *why* a start is unavailable rather than leaving a button that 409s.
const runningYear = computed(() =>
  tippYears.value.find(year => year.status === 'running') ?? null
)

/**
 * "Erledigt" is one status, not a date.
 *
 * A distributed year is closed business: everyone has their share and nothing
 * can be booked on it any more. Everything before that still owes something -
 * a planned year its start, a running one its tickets, a closed one its
 * distribution - so all three belong in the same view, however long ago they
 * ran.
 */
const isCurrent = year => year.status !== 'distributed'

const FILTERS = [
  { value: 'current', label: 'Aktuell', matches: isCurrent },
  { value: 'archive', label: 'Archiv', matches: year => !isCurrent(year) },
  { value: 'all', label: 'Alle', matches: () => true }
]

const filter = ref('current')

const matcher = value => FILTERS.find(option => option.value === value).matches

const visibleYears = computed(() => tippYears.value.filter(matcher(filter.value)))

const counted = value => tippYears.value.filter(matcher(value)).length

const loadYears = () => years.load(() => api.admin.getTippYears())

// --- B-18: Statuswechsel ---

/**
 * The row that was just written to, so the list can be found again.
 *
 * Reloading after a status change dropped the reader at the top of the page:
 * the table is replaced while the request is in flight, the page loses its
 * height, and the browser has nowhere to keep the scroll position. Keeping the
 * old table up during a refresh (see the loading state above) fixes the jump;
 * this brings the row back into view for the case where it was off-screen
 * anyway, and marks it so it can be found among twenty others.
 */
const yearTable = useTemplateRef('yearTable')
const changedId = ref(null)

const HIGHLIGHT_MS = 2500
let highlightTimer = null

function markChanged(tippYearId) {
  changedId.value = tippYearId

  clearTimeout(highlightTimer)
  highlightTimer = setTimeout(() => {
    changedId.value = null
  }, HIGHLIGHT_MS)

  // After the reloaded rows have rendered, or the row is not there to scroll
  // to - a year that has just been distributed leaves the current filter.
  nextTick(() => {
    yearTable.value?.querySelector(`[data-year="${tippYearId}"]`)?.scrollIntoView({
      block: 'nearest',
      behavior: window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
        ? 'auto'
        : 'smooth'
    })
  })
}

onUnmounted(() => clearTimeout(highlightTimer))

async function statusChanged(tippYearId) {
  await loadYears()
  markChanged(tippYearId)
}

function openWizard() {
  wizardOpen.value = true
}

/** The wizard has written everything itself - open the finished year. */
function finishWizard(tippYearId) {
  wizardOpen.value = false
  router.push({ name: 'AdminTippYear', params: { tippYearId } })
}

onMounted(() => {
  loadYears()
  // The wizard offers these for the new year's members, so only the ones still
  // playing (B-25) - B-11 refuses the others anyway.
  people.load(() => api.admin.getParticipants(true))
})
</script>

<style scoped>
.header-actions {
  display: flex;
  gap: 0.75rem;
}

.filters {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.filter {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.375rem 0.875rem;
  border: 1px solid var(--gray-300);
  border-radius: 999px;
  background: var(--white, #fff);
  color: var(--gray-600);
  font-size: 0.875rem;
  cursor: pointer;
}

.filter.active {
  border-color: var(--gray-900);
  background: var(--gray-900);
  color: #fff;
}

.filter .count {
  font-variant-numeric: tabular-nums;
  font-size: 0.75rem;
  opacity: 0.75;
}

/*
 * Where the change landed. It fades out on its own: with twenty rows on screen
 * the answer at the top of the page says *what* happened, and this says
 * *where* - it has no business staying once it has been seen.
 */
tr.just-changed td {
  animation: settle 2.5s ease-out;
}

@keyframes settle {
  0%,
  40% {
    background: #d1fae5;
  }

  100% {
    background: transparent;
  }
}

/* Still marked, just not animated - the mark is the point, the fade is not. */
@media (prefers-reduced-motion: reduce) {
  tr.just-changed td {
    animation: none;
    background: #d1fae5;
  }
}
</style>
