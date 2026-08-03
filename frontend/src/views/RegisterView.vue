<template>
  <div class="page-header">
    <div>
      <h1>Mitspielen</h1>
      <p class="subtitle">
        Anmeldung als Teilnehmer der Tippgemeinschaft.
      </p>
    </div>
  </div>

  <div
    v-if="query.loading && !query.data"
    class="state loading"
  >
    Wird geladen …
  </div>
  <div
    v-else-if="query.error"
    class="state error"
  >
    {{ query.error }}
  </div>

  <!-- Noch nichts abgeschickt: das Formular -->
  <div
    v-else-if="!registration.registered"
    class="card section"
  >
    <h3>Anmeldung abschicken</h3>

    <form @submit.prevent="register">
      <div class="field">
        <label for="displayName">Anzeigename</label>
        <input
          id="displayName"
          v-model="displayName"
          required
          minlength="2"
          maxlength="50"
          placeholder="Erika Mustermann"
        >
        <span class="hint">
          2 bis 50 Zeichen — unter diesem Namen erscheinst du in Reihen, Gebühren und
          Gewinnanteilen.
        </span>
      </div>

      <button
        class="btn-primary"
        :disabled="command.pending || displayName.trim().length < 2"
        type="submit"
      >
        {{ command.pending ? 'Wird gesendet …' : 'Anmeldung abschicken' }}
      </button>

      <p class="state note">
        Die Anmeldung geht an den Administrator. Erst wenn er sie freigibt, kannst du einem
        Tippjahr beitreten — dein Zugang bleibt derselbe, es ist nichts weiter einzurichten.
      </p>

      <CommandFeedback :command="command" />
    </form>
  </div>

  <!-- Abgeschickt: der Stand der Dinge -->
  <div
    v-else
    class="card section"
  >
    <h3>{{ registration.displayName }}</h3>

    <p
      v-if="registration.status === 'pending'"
      class="state note"
    >
      <strong>Deine Anmeldung liegt beim Administrator.</strong> Sobald sie freigegeben ist,
      erscheinen deine Reihe, deine Gebühren und dein Gewinnanteil von selbst — ohne dass du
      dich neu anmelden musst.
    </p>

    <p
      v-else-if="registration.status === 'active'"
      class="state success"
    >
      <strong>Du bist dabei.</strong>
      <RouterLink to="/bet-row">
        Zu deiner Tippreihe
      </RouterLink>
    </p>

    <p
      v-else
      class="state note"
    >
      <strong>Dieser Zugang spielt derzeit nicht mit.</strong> Entweder wurde die Anmeldung
      abgelehnt oder die Teilnahme wurde beendet. Der Administrator kann das ändern.
    </p>

    <button
      class="btn-secondary"
      :disabled="query.loading"
      @click="reload"
    >
      Aktualisieren
    </button>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import CommandFeedback from '@/components/CommandFeedback.vue'
import api from '@/services/api'
import { useCommand, useQuery } from '@/composables/useCommand'
import { useAuthStore } from '@/stores/auth'

/**
 * E1-01: the one page an account without a participant can do something on.
 *
 * Everything else in the participant area needs somebody to show data for.
 * This asks who that is - once - and afterwards reports where the request
 * stands. There is nothing to configure: the account is taken from the token,
 * so approval alone makes the other views work.
 */
const authStore = useAuthStore()

const query = useQuery()
const command = useCommand()

// Prefilled with what the realm knows, because most people would type exactly
// that - and it stays editable, because a login name is not always a name.
const displayName = ref(authStore.displayName === 'User' ? '' : authStore.displayName)

const registration = computed(() => query.data ?? { registered: false })

const reload = () => query.load(() => api.getMyRegistration())

async function register() {
  const accepted = await command.run(
    key => api.register({ displayName: displayName.value.trim() }, key)
  )

  if (accepted) {
    await reload()
    // The token does not change, but the account now has a participant behind
    // it - the store has to hear about it, or the navigation keeps treating
    // this as somebody the application does not know.
    await authStore.loadRegistration()
  }
}

onMounted(reload)
</script>

<style scoped>
.state.success a {
  margin-left: 0.5rem;
}
</style>
