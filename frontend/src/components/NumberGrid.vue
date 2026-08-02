<template>
  <div
    class="number-grid"
    role="group"
    :aria-label="`${PICK_COUNT} Zahlen von 1 bis ${HIGHEST} wählen`"
  >
    <button
      v-for="number in NUMBERS"
      :key="number"
      type="button"
      class="pick"
      :class="{ picked: isPicked(number) }"
      :aria-pressed="isPicked(number)"
      :disabled="complete && !isPicked(number)"
      @click="toggle(number)"
    >
      {{ number }}
    </button>
  </div>

  <p
    class="hint"
    aria-live="polite"
  >
    {{ caption }}
  </p>
</template>

<script setup>
import { computed } from 'vue'
import { formatNumbers } from '@/support/format'

/**
 * The six numbers, picked off a 7x7 grid instead of typed into a text field.
 *
 * The grid is the reason the views no longer parse anything: everything the
 * old text field could get wrong - a seventh number, a 50, a duplicate, a
 * decimal - is unreachable here, because the only two moves are picking a free
 * number and releasing a picked one. What is left over is the one rule a grid
 * cannot enforce by construction, "not yet six", and that one the submit
 * button carries.
 *
 * The domain still checks all of it in `LottoNumbers`; it always did, and it
 * remains the authority. This is a nicer way in, not a second guard.
 */
const PICK_COUNT = 6
const HIGHEST = 49
const NUMBERS = Array.from({ length: HIGHEST }, (_, index) => index + 1)

const picked = defineModel({ type: Array, required: true })

const complete = computed(() => picked.value.length >= PICK_COUNT)
const remaining = computed(() => PICK_COUNT - picked.value.length)

const caption = computed(() => {
  if (complete.value) {
    return `Gewählt: ${formatNumbers(picked.value)}`
  }

  return remaining.value === 1
    ? 'Noch eine Zahl wählen.'
    : `Noch ${remaining.value} Zahlen wählen.`
})

function isPicked(number) {
  return picked.value.includes(number)
}

function toggle(number) {
  if (isPicked(number)) {
    picked.value = picked.value.filter(candidate => candidate !== number)

    return
  }

  // The full grid locks its free numbers, so this only guards against a
  // programmatic call - a click cannot reach it.
  if (complete.value) {
    return
  }

  // Ascending, the order the domain keeps them in and the order the balls are
  // read back in. Sorting on the way in means no view has to sort on the way
  // out, whatever order the clicks came in.
  picked.value = [...picked.value, number].sort((a, b) => a - b)
}
</script>
