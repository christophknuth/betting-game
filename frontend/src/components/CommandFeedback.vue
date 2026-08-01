<template>
  <div
    v-if="command.error"
    class="state error feedback"
  >
    {{ command.error }}
  </div>

  <div
    v-else-if="command.result"
    class="state success feedback"
  >
    Angenommen.<template v-if="showId">
      Neue ID: <strong>#{{ command.result.resourceId }}</strong>.
    </template>
  </div>
</template>

<script setup>
import { computed } from 'vue'

/**
 * The answer to a command, reduced to the two things the person who pressed the
 * button needs: did it work, and if not, what went wrong.
 *
 * The `commandId` used to be printed here on every write, with a link to the
 * processing state. It is the handle for `GET /commands/{id}` and genuinely
 * useful - to whoever is reading a log, not to whoever is booking a fee. It
 * goes to the container's output now, next to the actor and the outcome (see
 * Kernel::executeCommand).
 *
 * The resource id stays, but only where the caller has to act on it: after
 * creating a participant it has to be entered into the realm by hand as their
 * `participant_id`, so hiding it would cost a lookup. Everywhere else it is a
 * number nobody types.
 */
const props = defineProps({
  command: {
    type: Object,
    required: true
  },

  /** Set where a newly created id is something the caller needs. */
  showResourceId: {
    type: Boolean,
    default: false
  }
})

const showId = computed(
  () => props.showResourceId && Boolean(props.command.result?.resourceId)
)
</script>

<style scoped>
.feedback {
  margin-top: 1rem;
}
</style>
