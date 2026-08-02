<template>
  <div class="page-header">
    <div>
      <h1>Seite nicht gefunden</h1>
      <p class="subtitle">
        Diese Adresse gibt es hier nicht.
      </p>
    </div>
  </div>

  <div class="card">
    <p>
      Möglich ist ein alter Link: Bis zur Umstellung auf die Tippgemeinschaft lag hier
      eine andere Anwendung, und deren Adressen führen ins Leere.
    </p>

    <div class="actions section">
      <router-link
        class="btn-primary"
        to="/bet-row"
      >
        Zu meiner Tippreihe
      </router-link>
    </div>
  </div>
</template>

<script setup>
/**
 * An unknown path used to redirect to the bet row view without a word.
 *
 * That is a jump nobody asked for: whoever followed a stale link ended up on a
 * page they did not choose, with no sign that the address was wrong. The URL
 * stays what was typed and the page says what happened - the way on is a link,
 * not an automatic move.
 */
</script>

<style scoped>
.actions {
  margin-top: 1rem;
}

.actions a {
  text-decoration: none;
  display: inline-block;
}
</style>
