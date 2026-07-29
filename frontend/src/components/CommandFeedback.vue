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
    <p>
      Angenommen.
      <template v-if="command.result.resourceId">
        Neue ID: <strong>#{{ command.result.resourceId }}</strong>.
      </template>
    </p>
    <p class="small">
      Command <code>{{ command.result.commandId }}</code> —
      <router-link :to="{ name: 'Operations', query: { commandId: command.result.commandId } }">
        Verarbeitungsstand ansehen
      </router-link>
    </p>
  </div>
</template>

<script setup>
/**
 * The answer to a command, in the shape the API actually sends it.
 *
 * `commandId` is worth showing rather than swallowing: it is the only handle a
 * caller has on `GET /commands/{id}`, and an idempotent retry looks up its
 * original result by exactly that id.
 */
defineProps({
  command: {
    type: Object,
    required: true
  }
})
</script>

<style scoped>
.feedback {
  margin-top: 1rem;
}

.small {
  font-size: 0.8125rem;
  margin-top: 0.375rem;
  opacity: 0.85;
}
</style>
