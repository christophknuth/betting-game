<template>
  <form @submit.prevent="submit">
    <div class="field">
      <span class="label">Angabe des Gewinns</span>
      <div class="modes">
        <label
          v-for="option in MODES"
          :key="option.value"
          class="checkbox"
        >
          <input
            v-model="mode"
            type="radio"
            :name="`mode-${drawId}`"
            :value="option.value"
            :disabled="option.value === 'classes' && !achieved.length"
          >
          {{ option.label }}
        </label>
      </div>
      <span
        v-if="!achieved.length"
        class="hint"
      >
        Keine Reihe dieses Scheins hat eine Gewinnklasse erreicht — es bleibt die Summe für
        den Schein, in der Regel 0,00 €.
      </span>
    </div>

    <div
      v-if="mode === 'total'"
      class="field"
    >
      <label :for="`amount-${drawId}`">Gewinn des gesamten Scheins</label>
      <MoneyInput
        :id="`amount-${drawId}`"
        v-model="total"
        required
      />
      <span class="hint">
        Ohne Aufschlüsselung rechnet das System die Treffer je Reihe selbst aus den
        Reihen-Snapshots des Scheins.
      </span>
    </div>

    <div
      v-else
      class="field"
    >
      <span class="label">Betrag je Gewinnklasse, für eine Reihe</span>
      <!--
        Nur die tatsächlich erreichten Klassen: welche das sind, steht seit B-22
        schon fest, sobald die Ziehung eingetragen ist. Die übrigen acht Felder
        wären Eingaben, die nichts bewirken können.
      -->
      <div class="classes">
        <div
          v-for="entry in achieved"
          :key="entry.winningClass"
          class="class-row"
        >
          <label :for="`class-${drawId}-${entry.winningClass}`">
            {{ winningClassLabel(entry.winningClass) }}
            <span class="rows">{{ entry.rowCount }} {{ entry.rowCount === 1 ? 'Reihe' : 'Reihen' }}</span>
          </label>
          <MoneyInput
            :id="`class-${drawId}-${entry.winningClass}`"
            v-model="perClass[entry.winningClass]"
          />
          <span class="line-total">{{ formatAmount(lineTotal(entry)) }}</span>
        </div>
      </div>
      <span class="hint">
        Der Betrag gilt für <strong>eine</strong> Reihe; multipliziert wird mit den Reihen
        der Klasse. Nur ausgefüllte Klassen werden gesendet.
      </span>
    </div>

    <p
      v-if="mode === 'classes'"
      class="sum"
      aria-live="polite"
    >
      Gewinn des Scheins: <strong>{{ formatAmount(sum) }}</strong>
    </p>

    <button
      class="btn-primary"
      :disabled="pending || !filled"
      type="submit"
    >
      {{ pending ? 'Wird gesendet …' : 'Gewinn eintragen' }}
    </button>
  </form>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import MoneyInput from '@/components/MoneyInput.vue'
import { formatAmount, winningClassLabel } from '@/support/format'

/**
 * B-23: what the ticket won, entered as one sum or class by class.
 *
 * Class by class the entry is the amount **one row** of that class was paid -
 * the figure the published statement gives. What it comes to is arithmetic:
 * amount times the rows of the ticket in that class, added up over the classes.
 * The API does that sum itself and is the authority on it; the running total
 * here is the same multiplication, shown so the result is visible before the
 * command goes out rather than after.
 *
 * Which classes the ticket achieved is known before any of this: the rows are
 * evaluated when the draw is recorded (B-22), so the form can offer exactly
 * those and say how many rows each of them holds.
 */
const MODES = [
  { value: 'total', label: 'Summe für den Schein' },
  { value: 'classes', label: 'Betrag je Gewinnklasse' }
]

const props = defineProps({
  drawId: { type: Number, required: true },

  /** The classes rows of this ticket achieved: `{ winningClass, rowCount }`. */
  winningClasses: { type: Array, default: () => [] },

  pending: { type: Boolean, default: false }
})

const emit = defineEmits(['submit'])

const mode = ref('total')

const achieved = computed(() =>
  props.winningClasses.filter(entry => entry.rowCount > 0)
)

// A draw whose rows won nothing has no classes to offer; if a reload brings
// some in later, the choice stays where the user left it.
watch(achieved, list => {
  if (!list.length) {
    mode.value = 'total'
  }
})

// Amounts, not strings: MoneyInput hands over a number or null, so nothing here
// has to know how the person typed it.
const total = ref(null)

// One entry per class, so v-model has something to bind to from the start -
// adding keys to a reactive object while the inputs render loses reactivity.
const perClass = reactive({})
watch(
  achieved,
  list => list.forEach(entry => {
    perClass[entry.winningClass] ??= null
  }),
  { immediate: true }
)

const lineTotal = entry => (perClass[entry.winningClass] ?? 0) * entry.rowCount

/** The classes that were actually filled in, as the API takes them. */
const entered = computed(() =>
  achieved.value
    .filter(entry => perClass[entry.winningClass] !== null && perClass[entry.winningClass] !== undefined)
    .map(entry => ({
      winningClass: entry.winningClass,
      amountPerRow: perClass[entry.winningClass]
    }))
)

const sum = computed(() =>
  // In cents, for the same reason the domain multiplies that way: three times
  // 0.10 is not 0.30 in binary floating point.
  achieved.value.reduce(
    (cents, entry) => cents + Math.round((perClass[entry.winningClass] ?? 0) * 100) * entry.rowCount,
    0
  ) / 100
)

const filled = computed(() => (mode.value === 'total' ? total.value !== null : entered.value.length > 0))

function submit() {
  if (!filled.value) {
    return
  }

  emit(
    'submit',
    mode.value === 'total'
      ? { totalAmount: total.value }
      : { winningClasses: entered.value }
  )
}
</script>

<style scoped>
.modes {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
}

.modes .checkbox {
  margin-bottom: 0;
}

.classes {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 0.5rem 1rem;
}

.class-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}

.class-row label {
  font-size: 0.8125rem;
  color: var(--gray-600);
}

.class-row .rows {
  display: block;
  font-size: 0.75rem;
  color: var(--gray-500);
}

/* The child component's root element - it brings its own border and € */
.class-row :deep(.money) {
  width: 7rem;
  padding: 0.375rem 0.5rem;
}

.line-total {
  min-width: 5rem;
  text-align: right;
  font-size: 0.8125rem;
  font-variant-numeric: tabular-nums;
  color: var(--gray-600);
}

.sum {
  margin-bottom: 1rem;
  font-variant-numeric: tabular-nums;
}
</style>
