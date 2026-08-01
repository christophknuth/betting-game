<template>
  <slot v-if="authStore.participantId" />

  <div
    v-else
    class="state note"
  >
    <p>
      <strong>Dein Zugang ist noch keinem Teilnehmer zugeordnet.</strong>
    </p>
    <p>
      Deshalb gibt es hier nichts zu zeigen — deine eigenen Daten erscheinen, sobald ein
      Administrator die Zuordnung eingetragen hat. Bitte wende dich an ihn.
    </p>
  </div>
</template>

<script setup>
import { onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()

/**
 * What the panel above used to say, and no longer does.
 *
 * It named the missing `participant_id` claim, explained that identity comes
 * from the token rather than the URL, and - when the token carried no roles
 * either - pointed at the realm's client scopes. All of it true, none of it
 * anything the person reading it can act on: the fix is an attribute in
 * Keycloak that only an administrator can set.
 *
 * So the person gets a sentence they can act on, and the diagnosis goes to the
 * browser console, where whoever is actually debugging this will look. It
 * cannot reach the container log from here - nothing in the browser can - and
 * the server-side counterpart is AuthMiddleware, which already logs a rejected
 * token.
 */
onMounted(() => {
  if (authStore.participantId) {
    return
  }

  if (!authStore.roles.length) {
    console.warn(
      'The token carries neither participant_id nor any role. That points at the realm '
      + 'rather than the user: the betting-game-frontend client most likely has no client '
      + 'scopes assigned. See KEYCLOAK.md, "One client scope in the realm export deletes '
      + 'the built-in ones".'
    )

    return
  }

  console.warn(
    'The token carries no participant_id claim, so the participant views have nobody to '
    + 'show data for. It is mapped from the user attribute of the same name in the realm.'
  )
})
</script>

<style scoped>
p + p {
  margin-top: 0.5rem;
}
</style>
