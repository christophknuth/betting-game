<template>
  <div
    class="number-grid superzahl-row"
    role="radiogroup"
    aria-label="Superzahl von 0 bis 9"
  >
    <button
      v-for="digit in DIGITS"
      :key="digit"
      type="button"
      class="pick superzahl"
      :class="{ picked: digit === picked }"
      role="radio"
      :aria-checked="digit === picked"
      @click="toggle(digit)"
    >
      {{ digit }}
    </button>
  </div>
</template>

<script setup>
/**
 * The Superzahl, picked the way the six numbers are.
 *
 * It sat next to the 7x7 grid as a plain number field with `min`/`max`, which
 * made one slip of the ticket two different kinds of entry. Ten balls is the
 * same gesture and the same shape, and the field's one advantage - typing a
 * digit - is not worth much for a range of ten.
 *
 * Yellow rather than blue, because that is the colour the Superzahl already
 * has wherever a draw is displayed.
 */
const DIGITS = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9]

const picked = defineModel({ type: Number, default: null })

function toggle(digit) {
  // Clicking the chosen one again clears it - the same way out the number grid
  // gives, and the only one when a digit was hit by accident.
  picked.value = picked.value === digit ? null : digit
}
</script>

<style scoped>
.superzahl-row {
  grid-template-columns: repeat(10, 1fr);
  max-width: 24rem;
}

.pick.superzahl.picked {
  border-color: var(--yellow);
  background: var(--yellow);
  color: white;
}
</style>
