<template>
  <slot v-if="authStore.participantId" />

  <div
    v-else
    class="state note"
  >
    <p>
      <strong>Dieses Token trägt keinen <code>participant_id</code>-Claim.</strong>
    </p>
    <p>
      Die Teilnehmeransichten zeigen ausschließlich die eigenen Daten und leiten die
      Identität aus dem Token ab, nie aus der URL. Ohne den Claim gibt es niemanden,
      dessen Daten gezeigt werden dürften — auch für einen Administrator nicht, der
      dafür die Admin-Ansichten hat.
    </p>
    <p v-if="!authStore.roles.length">
      <strong>Das Token trägt auch keine Rollen.</strong> Dann liegt es nicht am
      Benutzer, sondern am Realm: Der Client <code>betting-game-frontend</code> hat
      vermutlich keine Client Scopes zugewiesen. Siehe <code>KEYCLOAK.md</code>,
      Abschnitt „Ein Client Scope im Realm-Export löscht die eingebauten“.
    </p>
  </div>
</template>

<script setup>
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()
</script>

<style scoped>
p + p {
  margin-top: 0.5rem;
}
</style>
