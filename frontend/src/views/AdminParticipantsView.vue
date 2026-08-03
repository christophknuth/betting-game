<template>
  <div class="page-header">
    <div>
      <h1>Teilnehmer</h1>
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
          <th scope="col">
            ID
          </th>
          <th scope="col">
            Anzeigename
          </th>
          <th scope="col">
            Status
          </th>
          <th scope="col">
            Angelegt
          </th>
          <th scope="col" />
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="participant in participants"
          :key="participant.participantId"
          :class="{ inactive: !participant.isActive }"
        >
          <td>#{{ participant.participantId }}</td>

          <!-- B-25: bearbeitet wird in der Zeile. Der Name steht in einer
               Tabelle unter neunzehn anderen — ihn dafür an eine andere
               Stelle zu holen, verliert genau den Vergleich, wegen dem man
               ihn ändert. -->
          <td>
            <form
              v-if="editingId === participant.participantId"
              class="rename"
              @submit.prevent="rename(participant)"
            >
              <input
                ref="renameField"
                v-model="editingName"
                :aria-label="`Anzeigename von ${participant.displayName}`"
                required
                minlength="2"
                maxlength="50"
              >
              <button
                class="btn-primary"
                :disabled="!renameable(participant) || pending(participant)"
                type="submit"
              >
                Speichern
              </button>
              <button
                class="btn-link"
                type="button"
                @click="stopEditing"
              >
                Abbrechen
              </button>
            </form>
            <template v-else>
              {{ participant.displayName }}
            </template>
          </td>

          <td>
            <span
              class="badge"
              :class="participant.isActive ? 'active' : 'ended'"
            >{{ participant.isActive ? 'aktiv' : 'inaktiv' }}</span>
          </td>
          <td>{{ formatDateTime(participant.registeredAt) }}</td>

          <td class="actions">
            <button
              v-if="editingId !== participant.participantId"
              class="btn-link"
              type="button"
              @click="startEditing(participant)"
            >
              umbenennen
            </button>
            <button
              class="btn-link"
              type="button"
              :disabled="pending(participant)"
              @click="changeStatus(participant)"
            >
              {{ participant.isActive ? 'deaktivieren' : 'aktivieren' }}
            </button>
          </td>
        </tr>
      </tbody>
    </table>

    <p class="state note">
      Ein Teilnehmer wird nicht gelöscht: an ihm hängen Teilnahmen, Gebühren und Anteile
      vergangener Jahre. <strong>Deaktiviert</strong> heißt „spielt nicht mehr mit" — er wird
      keinem Tippjahr mehr angeboten, alles Gebuchte bleibt.
    </p>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, reactive, ref, useTemplateRef, watch } from 'vue'
import CommandFeedback from '@/components/CommandFeedback.vue'
import api from '@/services/api'
import { useCommand, useQuery } from '@/composables/useCommand'
import { formatDateTime } from '@/support/format'
import { useNotificationStore } from '@/stores/notifications'

const query = useQuery()
const command = useCommand()
const notifications = useNotificationStore()

const form = reactive({ displayName: '' })

// The roster shows everybody, active or not - it is the one place where an
// administrator has to see who has left in order to bring them back.
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

// --- B-25: umbenennen und (de)aktivieren ---

const editingId = ref(null)
const editingName = ref('')
const renameField = useTemplateRef('renameField')

/**
 * One command state per participant, not one for the page.
 *
 * They must not share an idempotency key: a key left over from one row would,
 * on the next row's button, replay that first answer instead of writing this
 * one (OPS-02).
 */
const rowCommands = reactive({})
const commandFor = participant => (rowCommands[participant.participantId] ??= useCommand())

// Created before the rows render, not while they render - reaching into a
// reactive map from a template would mutate state mid-render.
watch(participants, list => list.forEach(commandFor), { immediate: true })

const pending = participant => rowCommands[participant.participantId]?.pending ?? false

function startEditing(participant) {
  editingId.value = participant.participantId
  editingName.value = participant.displayName

  // The field appears with the click, so the focus has to wait for it - and
  // whoever pressed "umbenennen" wants to type, not to click again. A ref
  // inside v-for collects into an array, even where only one row renders it.
  nextTick(() => {
    const field = renameField.value

    ;(Array.isArray(field) ? field[0] : field)?.focus()
  })
}

function stopEditing() {
  editingId.value = null
  editingName.value = ''
}

/** An unchanged name is a `409` from the API, so the button says so first. */
const renameable = participant => {
  const name = editingName.value.trim()

  return name.length >= 2 && name !== participant.displayName
}

async function rename(participant) {
  if (!renameable(participant)) {
    return
  }

  const cmd = commandFor(participant)
  const accepted = await cmd.run(key => api.admin.renameParticipant(
    participant.participantId,
    { displayName: editingName.value.trim() },
    key
  ))

  // Reported by hand rather than through CommandFeedback: these commands hang
  // off a table row and have no form of their own to sit under.
  if (!accepted) {
    notifications.error(cmd.error)

    return
  }

  notifications.success(`${participant.displayName} heißt jetzt ${editingName.value.trim()}.`)
  stopEditing()
  await reload()
}

async function changeStatus(participant) {
  const isActive = !participant.isActive

  const cmd = commandFor(participant)
  const accepted = await cmd.run(key => api.admin.changeParticipantStatus(
    participant.participantId,
    { isActive },
    key
  ))

  if (!accepted) {
    notifications.error(cmd.error)

    return
  }

  notifications.success(
    `${participant.displayName} ist jetzt ${isActive ? 'aktiv' : 'inaktiv'}.`
  )
  await reload()
}

onMounted(reload)
</script>

<style scoped>
.rename {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.rename input {
  max-width: 16rem;
}

.actions {
  display: flex;
  gap: 0.75rem;
  white-space: nowrap;
}

/* Still readable, visibly out of play - the badge says which, this says how
   much attention the row deserves. */
tr.inactive td {
  color: var(--gray-500);
}
</style>
