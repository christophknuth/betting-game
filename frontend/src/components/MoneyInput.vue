<template>
  <div
    class="money"
    :class="{ invalid }"
  >
    <input
      :id="id"
      v-model="text"
      type="text"
      inputmode="decimal"
      class="money-field"
      :placeholder="placeholder"
      :required="required"
      :aria-invalid="invalid"
      @input="onInput"
      @blur="onBlur"
    >
    <span class="currency">€</span>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import { formatDecimal, parseAmount } from '@/support/format'

/**
 * One amount, entered the way the rest of the interface writes it.
 *
 * Every figure in this SPA is shown as `1,20 €`, and every field that took one
 * used to ask for `1.20` - a plain `type="number"`, whose value attribute is
 * defined to use a dot no matter the locale. Typing the comma the label implies
 * left the field empty in browsers that take that definition literally, without
 * saying so.
 *
 * So the field is text with `inputmode="decimal"`: the numeric keypad on a
 * phone, both separators accepted (see parseAmount), and on blur the entry is
 * rounded to cents and rewritten as `1,20`. What is shown and what is sent
 * cannot drift apart, because the rounded value is what gets emitted.
 */
const props = defineProps({
  id: { type: String, default: undefined },
  placeholder: { type: String, default: '0,00' },
  required: { type: Boolean, default: false },
  /** Below this the entry is refused rather than sent; no amount here is ever negative. */
  min: { type: Number, default: 0 }
})

const model = defineModel({ type: Number, default: null })

const text = ref(formatDecimal(model.value))
const invalid = ref(false)

/**
 * A value set from outside - a reset after a booking, a loaded draft - has to
 * reach the field. What is being typed must not: rewriting `1,` into `1,00`
 * mid-keystroke moves the caret and fights the person typing.
 */
watch(model, value => {
  if (parseAmount(text.value) !== value) {
    text.value = formatDecimal(value)
    invalid.value = false
  }
})

function onInput() {
  const amount = parseAmount(text.value)

  // An empty field is not an error, it is an empty field. Anything else that
  // does not read as an amount is one, and says so before the form is sent.
  invalid.value = text.value.trim() !== '' && (amount === null || amount < props.min)
  model.value = invalid.value ? null : amount
}

/**
 * Read back out of the field rather than off the model: the field is what was
 * typed, and the model is only as current as the parent's last echo of it.
 */
function onBlur() {
  const amount = parseAmount(text.value)

  if (amount === null || amount < props.min) {
    return
  }

  // Rounded first, then shown: cents are what the API is given, and a field
  // reading 1,23 while 1,234 is on its way would be a small lie about money.
  const rounded = Math.round(amount * 100) / 100

  model.value = rounded
  text.value = formatDecimal(rounded)
}
</script>

<style scoped>
.money {
  display: flex;
  align-items: center;
  gap: 0.375rem;
  border: 1px solid var(--gray-300);
  border-radius: 6px;
  padding: 0.5rem 0.625rem;
  background: white;
}

.money:focus-within {
  outline: 2px solid var(--blue);
  outline-offset: -1px;
  border-color: var(--blue);
}

.money.invalid {
  border-color: var(--red);
}

/* The border lives on the wrapper, so that the € sits inside it */
.money-field {
  flex: 1;
  min-width: 0;
  border: none;
  padding: 0;
  font: inherit;
  color: var(--gray-900);
  background: none;
  text-align: right;
  font-variant-numeric: tabular-nums;
}

.money-field:focus {
  outline: none;
}

.currency {
  color: var(--gray-600);
}
</style>
