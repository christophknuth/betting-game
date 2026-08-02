<template>
  <div
    ref="grid"
    class="number-grid"
    role="group"
    :aria-label="`${PICK_COUNT} Zahlen von 1 bis ${HIGHEST} wählen`"
    @keydown="onKeydown"
  >
    <button
      v-for="number in NUMBERS"
      :key="number"
      type="button"
      class="pick"
      :class="{ picked: isPicked(number), locked: locked(number) }"
      :aria-pressed="isPicked(number)"
      :aria-disabled="locked(number)"
      :tabindex="number === active ? 0 : -1"
      @click="toggle(number)"
      @focus="active = number"
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
import { computed, nextTick, ref, useTemplateRef } from 'vue'
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

/**
 * Full grid, free number: it can be reached but not taken.
 *
 * `aria-disabled` rather than `disabled`, because a disabled button cannot be
 * focused - and a keyboard walking the grid has to be able to pass over the
 * locked numbers to reach the picked ones beyond them.
 */
function locked(number) {
  return complete.value && !isPicked(number)
}

function toggle(number) {
  if (isPicked(number)) {
    picked.value = picked.value.filter(candidate => candidate !== number)

    return
  }

  // aria-disabled does not stop a click the way disabled did, so the rule is
  // enforced here rather than by the browser.
  if (complete.value) {
    return
  }

  // Ascending, the order the domain keeps them in and the order the balls are
  // read back in. Sorting on the way in means no view has to sort on the way
  // out, whatever order the clicks came in.
  picked.value = [...picked.value, number].sort((a, b) => a - b)
}

/**
 * One tab stop for the whole grid, and the arrow keys inside it.
 *
 * Forty-nine buttons meant forty-nine presses of Tab before the submit button
 * came within reach. The roving tabindex above puts exactly one of them in the
 * tab order, and this moves that one - the pattern a grid of controls is
 * expected to follow, and the reason the locked numbers keep their focus.
 */
const COLUMNS = 7

const grid = useTemplateRef('grid')
const active = ref(1)

const STEPS = {
  ArrowRight: 1,
  ArrowLeft: -1,
  ArrowDown: COLUMNS,
  ArrowUp: -COLUMNS
}

function focusNumber(number) {
  active.value = number

  // After the tabindex has moved with it, or the browser refuses the focus
  nextTick(() => grid.value?.querySelector(`[tabindex="0"]`)?.focus())
}

function onKeydown(event) {
  if (event.key === 'Home' || event.key === 'End') {
    event.preventDefault()
    focusNumber(event.key === 'Home' ? 1 : HIGHEST)

    return
  }

  const step = STEPS[event.key]

  if (step === undefined) {
    return
  }

  const target = active.value + step

  // Stop at the edges rather than wrapping: 7 to the right of 49 would be off
  // the board, and wrapping to 1 would look like the grid had been reset.
  if (target >= 1 && target <= HIGHEST) {
    event.preventDefault()
    focusNumber(target)
  }
}
</script>
