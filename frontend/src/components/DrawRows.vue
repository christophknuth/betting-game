<template>
  <div
    v-if="!rows.length"
    class="state empty"
  >
    Für diese Ziehung sind keine Reihen des Tippscheins hinterlegt.
  </div>

  <ul
    v-else
    class="rows"
  >
    <li
      v-for="row in rows"
      :key="row.ticketRowId"
      class="row"
      :class="{ winner: row.winningClass !== null }"
    >
      <div class="who">
        <strong>{{ row.displayName }}</strong>
        <span
          v-if="row.winningClass !== null"
          class="badge ok"
        >{{ winningClassLabel(row.winningClass) }}</span>
        <span
          v-else
          class="hint"
        >{{ matchLabel(row) }}</span>
      </div>

      <div class="numbers small">
        <span
          v-for="number in row.numbers"
          :key="number"
          class="ball"
          :class="{ miss: !drawnNumbers.includes(number) }"
        >{{ number }}</span>
      </div>

      <div
        v-if="row.winningClass !== null"
        class="won"
      >
        {{ formatAmount(row.amount) }}
      </div>
    </li>
  </ul>
</template>

<script setup>
import { computed } from 'vue'
import { formatAmount, winningClassLabel } from '@/support/format'

/**
 * B-24: the rows the ticket carried into a draw, with the winners marked.
 *
 * The marking is doubled on purpose. A row that reached a winning class is
 * highlighted as a whole and says which class - that is the question being
 * asked. Within every row the numbers that were *not* drawn are greyed back,
 * so the hits stay in the syndicate's colour and a near miss can be read off
 * at a glance rather than counted.
 *
 * Losing rows are shown, not hidden: "3 Richtige" and "nothing" are both
 * results, and a list of only the winners would look like a ticket that never
 * had the other rows on it.
 */
const props = defineProps({
  rows: { type: Array, required: true },
  numbers: { type: Array, default: () => [] }
})

// A draw whose numbers are missing is scheduled, not drawn - then nothing is a
// hit, rather than everything.
const drawnNumbers = computed(() => props.numbers ?? [])

function matchLabel(row) {
  if (row.matchedNumbers === null) {
    return 'noch nicht ausgewertet'
  }

  return `${row.matchedNumbers} Richtige${row.superzahlMatched ? ' + Superzahl' : ''}`
}
</script>

<style scoped>
.rows {
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem 1rem;
  padding: 0.5rem 0.75rem;
  border: 1px solid var(--gray-100);
  border-radius: 8px;
}

.row.winner {
  border-color: var(--green);
  background: #ecfdf5;
}

.who {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  min-width: 12rem;
}

/* Not drawn, so it steps back - the hits keep the ball colour */
.numbers .ball.miss {
  background: var(--gray-100);
  color: var(--gray-400);
}

.won {
  margin-left: auto;
  font-weight: 600;
  color: var(--green);
  font-variant-numeric: tabular-nums;
}
</style>
