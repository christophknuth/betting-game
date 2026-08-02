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
          >
          {{ option.label }}
        </label>
      </div>
    </div>

    <div
      v-if="mode === 'total'"
      class="field"
    >
      <label :for="`amount-${drawId}`">Gewinn des gesamten Scheins</label>
      <input
        :id="`amount-${drawId}`"
        v-model="total"
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

    <div
      v-else
      class="field"
    >
      <span class="label">Beträge je Gewinnklasse</span>
      <div class="classes">
        <div
          v-for="(label, winningClass) in WINNING_CLASSES"
          :key="winningClass"
          class="class-row"
        >
          <label :for="`class-${drawId}-${winningClass}`">{{ label }}</label>
          <input
            :id="`class-${drawId}-${winningClass}`"
            v-model="perClass[winningClass]"
            type="number"
            step="0.01"
            min="0"
            placeholder="0,00"
          >
        </div>
      </div>
      <span class="hint">
        Nur ausgefüllte Klassen werden gesendet. Die Summe daraus ist der Gewinn des
        Scheins — sie muss nicht zusätzlich eingetragen werden.
      </span>
    </div>

    <p
      v-if="mode === 'classes'"
      class="sum"
      aria-live="polite"
    >
      Summe: <strong>{{ formatAmount(sum) }}</strong>
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
import { computed, reactive, ref } from 'vue'
import { WINNING_CLASSES, formatAmount } from '@/support/format'

/**
 * B-23: what the ticket won, entered as one sum or class by class.
 *
 * The lottery statement comes in both shapes, and neither is derivable from
 * the other by the person reading it: a single figure says nothing about the
 * classes, and adding nine of them up by hand is exactly the arithmetic the
 * system should be doing. So both are offered and only one is ever sent - with
 * the classes, the API adds them up into the ticket's total itself.
 */
const MODES = [
  { value: 'total', label: 'Summe für den Schein' },
  { value: 'classes', label: 'Beträge je Gewinnklasse' }
]

defineProps({
  drawId: { type: Number, required: true },
  pending: { type: Boolean, default: false }
})

const emit = defineEmits(['submit'])

const mode = ref('total')
const total = ref('')

// One entry per class, so v-model has something to bind to from the start -
// adding keys to a reactive object while the inputs render loses reactivity.
const perClass = reactive(
  Object.fromEntries(Object.keys(WINNING_CLASSES).map(winningClass => [winningClass, '']))
)

/** The classes that were actually filled in, as the API takes them. */
const entered = computed(() =>
  Object.entries(perClass)
    .filter(([, amount]) => amount !== '' && amount !== null)
    .map(([winningClass, amount]) => ({
      winningClass: Number(winningClass),
      amount: Number(amount)
    }))
)

const sum = computed(() =>
  // In cents, for the same reason the domain adds them up that way: three
  // times 0.10 is not 0.30 in binary floating point.
  entered.value.reduce((cents, entry) => cents + Math.round(entry.amount * 100), 0) / 100
)

const filled = computed(() => (mode.value === 'total' ? total.value !== '' : entered.value.length > 0))

function submit() {
  if (!filled.value) {
    return
  }

  emit(
    'submit',
    mode.value === 'total'
      ? { totalAmount: Number(total.value) }
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
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
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

.class-row input {
  width: 7rem;
  padding: 0.375rem 0.5rem;
  border: 1px solid var(--gray-300);
  border-radius: 6px;
  font: inherit;
  text-align: right;
}

.sum {
  margin-bottom: 1rem;
  font-variant-numeric: tabular-nums;
}
</style>
