<template>
  <slot v-if="authStore.participantId" />

  <div v-else class="state note">
    <p>
      <strong>Dieses Token trägt keinen <code>participant_id</code>-Claim.</strong>
    </p>
    <p>
      Die Teilnehmeransichten zeigen ausschließlich die eigenen Daten und leiten die
      Identität aus dem Token ab, nie aus der URL. Ohne den Claim gibt es niemanden,
      dessen Daten gezeigt werden dürften — auch für einen Administrator nicht, der
      dafür die Admin-Ansichten hat.
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
