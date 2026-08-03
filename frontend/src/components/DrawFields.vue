<template>
  <div class="field">
    <label :for="`${idPrefix}-date`">Ziehungsdatum</label>
    <input
      :id="`${idPrefix}-date`"
      :value="drawDate"
      type="date"
      required
      @input="$emit('update:drawDate', $event.target.value)"
    >
  </div>

  <div class="field">
    <span class="label">Gewinnzahlen</span>
    <NumberGrid
      :model-value="numbers"
      @update:model-value="value => $emit('update:numbers', value)"
    />
  </div>

  <div class="field">
    <span class="label">Superzahl</span>
    <SuperzahlPicker
      :model-value="superzahl"
      @update:model-value="value => $emit('update:superzahl', value)"
    />
  </div>
</template>

<script setup>
import NumberGrid from '@/components/NumberGrid.vue'
import SuperzahlPicker from '@/components/SuperzahlPicker.vue'

/**
 * The three things a draw is: a day, six numbers, a Superzahl.
 *
 * Recording one (B-08) and correcting one (B-28) ask for exactly the same
 * fields, and a correction that offered a different set of inputs than the
 * entry it corrects would be a second place for the same rules. The ids are
 * prefixed because both can be on the page at once - a label pointing at the
 * wrong field is the kind of fault nobody sees until a screen reader does.
 */
defineProps({
  idPrefix: { type: String, required: true },
  drawDate: { type: String, default: '' },
  numbers: { type: Array, default: () => [] },
  superzahl: { type: Number, default: null }
})

defineEmits(['update:drawDate', 'update:numbers', 'update:superzahl'])
</script>
