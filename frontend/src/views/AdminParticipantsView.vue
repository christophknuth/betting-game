<template>
  <div class="page-header">
    <div>
      <h2>Teilnehmer</h2>
      <p class="subtitle">
        Wer mitspielen kann. Ein Teilnehmer wird hier angelegt und danach einem Tippjahr
        zugeordnet — beides macht der Administrator, Selbstregistrierung ist E1.
      </p>
    </div>
  </div>

  <!-- B-21 -->
  <div class="card section">
    <h3>Teilnehmer anlegen</h3>

    <form @submit.prevent="create">
      <div class="field-inline">
        <div class="field">
          <label for="displayName">Anzeigename</label>
          <input
            id="displayName"
            v-model="form.displayName"
            required
            minlength="2"
            maxlength="50"
            placeholder="Erika Mustermann"
          >
          <span class="hint">2 bis 50 Zeichen</span>
        </div>

        <button
          class="btn-primary"
          :disabled="command.pending"
          type="submit"
        >
          {{ command.pending ? 'Wird gesendet …' : 'Anlegen' }}
        </button>
      </div>

      <p class="state note">
        Damit jemand seine eigenen Daten sieht, muss die hier vergebene ID im Keycloak-Realm
        als Attribut <code>participant_id</code> beim Benutzer eingetragen werden.
      </p>

      <!--
        The one place the new id is worth showing: it has to be entered into
        the realm by hand as the user's participant_id, or they see nobody's
        data (E1-01 would close that).
      -->
      <CommandFeedback
        :command="command"
        show-resource-id
      />
    </form>
  </div>

  <div
    v-if="query.loading"
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
  <div
    v-else-if="!participants.length"
    class="state empty"
  >
    Noch kein Teilnehmer angelegt.
  </div>

  <div
    v-else
    class="card table-wrap"
  >
    <table class="data">
      <thead>
        <tr>
          <th>ID</th>
          <th>Anzeigename</th>
          <th>Status</th>
          <th>Angelegt</th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="participant in participants"
          :key="participant.participantId"
        >
          <td>#{{ participant.participantId }}</td>
          <td>{{ participant.displayName }}</td>
          <td>
            <span
              class="badge"
              :class="participant.isActive ? 'active' : 'ended'"
            >{{ participant.isActive ? 'aktiv' : 'inaktiv' }}</span>
          </td>
          <td>{{ formatDateTime(participant.registeredAt) }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive } from 'vue'
import CommandFeedback from '@/components/CommandFeedback.vue'
import api from '@/services/api'
import { useCommand, useQuery } from '@/composables/useCommand'
import { formatDateTime } from '@/support/format'

const query = useQuery()
const command = useCommand()

const form = reactive({ displayName: '' })

const participants = computed(() => query.data?.participants ?? [])

const reload = () => query.load(() => api.admin.getParticipants())

async function create() {
  const accepted = await command.run(
    key => api.admin.createParticipant({ displayName: form.displayName.trim() }, key)
  )

  if (accepted) {
    form.displayName = ''
    await reload()
  }
}

onMounted(reload)
</script>
