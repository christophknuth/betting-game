<template>
  <select
    :value="year.status"
    class="status-select"
    :class="year.status"
    :disabled="cmd.pending"
    :aria-label="`Status von ${year.name}`"
    @change="change"
  >
    <option
      v-for="status in TIPP_YEAR_STATUSES"
      :key="status"
      :value="status"
    >
      {{ statusLabel(status) }}
    </option>
  </select>
</template>

<script setup>
/**
 * B-18: the status of a tipp year, as the one control that can set it.
 *
 * A dropdown rather than a row of buttons for the next step, because every
 * transition is allowed - a year closed too early has to be reopenable, and
 * that correction belongs in the event history rather than in a manual UPDATE.
 *
 * A component rather than markup in both views: the list and the year's own
 * page each need it, and each instance brings its own command state. That is
 * not a detail - two rows sharing an idempotency key would let a request that
 * never came back replay its answer for the wrong year.
 */
import api from '@/services/api'
import { useCommand } from '@/composables/useCommand'
import { TIPP_YEAR_STATUSES, statusLabel } from '@/support/format'
import { useNotificationStore } from '@/stores/notifications'

const props = defineProps({
  year: { type: Object, required: true }
})

const emit = defineEmits(['changed'])

const cmd = useCommand()
const notifications = useNotificationStore()

async function change(event) {
  const status = event.target.value
  const accepted = await cmd.run(
    key => api.admin.changeTippYearStatus(props.year.tippYearId, { status }, key)
  )

  if (!accepted) {
    // Reported by hand rather than through CommandFeedback: this command has
    // no form of its own, it hangs off a dropdown.
    notifications.error(cmd.error)

    // Put the dropdown back by hand. Vue will not do it: the model never
    // changed, so from its side there is nothing to patch - only the DOM is
    // showing a status the server just refused.
    event.target.value = props.year.status

    return
  }

  // Names both halves rather than saying "Angenommen.": in a list of years
  // "which year" is the part that is easy to get wrong, and this is the only
  // place the answer confirms what was actually written.
  notifications.success(`${props.year.name} ist jetzt ${statusLabel(status)}.`)

  emit('changed', props.year.tippYearId)
}
</script>

<style scoped>
/* Looks like the badge that used to sit here - but is operable. */
.status-select {
  padding: 0.125rem 0.5rem;
  border: 1px solid var(--gray-300);
  border-radius: 12px;
  font-size: 0.8125rem;
  font-weight: 600;
  background: var(--gray-100);
  color: var(--gray-600);
  cursor: pointer;
}

.status-select:disabled {
  opacity: 0.6;
  cursor: wait;
}

.status-select.running {
  background: #d1fae5;
  color: #065f46;
}

.status-select.planned {
  background: #fef3c7;
  color: #92400e;
}

.status-select.closed,
.status-select.distributed {
  background: #dbeafe;
  color: #1e40af;
}
</style>
