<template>
  <slot v-if="authStore.participantId" />

  <div
    v-else
    class="state note"
  >
    <p>
      <strong>{{ pending ? 'Deine Anmeldung wird noch geprüft.' : 'Du spielst noch nicht mit.' }}</strong>
    </p>
    <p v-if="pending">
      Deshalb gibt es hier noch nichts zu zeigen — deine eigenen Daten erscheinen, sobald
      der Administrator die Anmeldung freigegeben hat.
      <RouterLink to="/register">
        Zum Stand der Anmeldung
      </RouterLink>
    </p>
    <p v-else>
      Deine eigenen Daten erscheinen, sobald du als Teilnehmer angemeldet und freigegeben
      bist.
      <RouterLink to="/register">
        Jetzt anmelden
      </RouterLink>
    </p>
  </div>
</template>

<script setup>
import { computed, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()

/** E1-01: waiting for approval reads differently from never having asked. */
const pending = computed(() => authStore.registration?.status === 'pending')

/**
 * What the panel above used to say, and no longer does.
 *
 * It named the missing `participant_id` claim, explained that identity comes
 * from the token rather than the URL, and - when the token carried no roles
 * either - pointed at the realm's client scopes. All of it true, none of it
 * anything the person reading it could act on.
 *
 * Since E1-01 there is something they can do, and it is a link rather than a
 * request to an administrator: registering is what creates the participant the
 * views are missing. The diagnosis stays in the browser console, where whoever
 * is actually debugging a realm will look.
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

  // Since E1-01 a missing claim is the normal case rather than a fault: the
  // API recognises the account by its subject once a registration exists. Only
  // "no registration either" is worth a line.
  if (!authStore.registration?.registered) {
    console.info(
      'This account has no participant_id claim and no registration, so the participant '
      + 'views have nobody to show data for. POST /registrations is how one is created.'
    )
  }
})
</script>

<style scoped>
p + p {
  margin-top: 0.5rem;
}
</style>
