<template>
  <div class="page-header">
    <div>
      <h2>Betrieb</h2>
      <p class="subtitle">
        Verarbeitungsstand der Commands (OPS-01), Event-Historie (OPS-03) und die
        Projektionen (OPS-04).
      </p>
    </div>
  </div>

  <!-- OPS-04 -->
  <div class="section">
    <div class="page-header">
      <h3>Projektionen</h3>
      <div class="actions">
        <button class="btn-secondary" :disabled="projections.loading" @click="loadProjections">
          Aktualisieren
        </button>
        <button class="btn-danger" :disabled="rebuilding" @click="rebuild('all')">
          Alle neu aufbauen
        </button>
      </div>
    </div>

    <div v-if="projections.loading" class="state loading">Wird geladen …</div>
    <div v-else-if="projections.error" class="state error">{{ projections.error }}</div>

    <div v-else class="card table-wrap">
      <table class="data">
        <thead>
          <tr>
            <th>Projektion</th>
            <th>Status</th>
            <th class="numeric">Verarbeitet</th>
            <th class="numeric">Head</th>
            <th class="numeric">Rückstand</th>
            <th>Aktualisiert</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="projection in projectionList" :key="projection.name">
            <td><code>{{ projection.name }}</code></td>
            <td>
              <span class="badge" :class="projection.upToDate ? 'ok' : 'lagging'">
                {{ projection.upToDate ? 'aktuell' : projection.status }}
              </span>
            </td>
            <td class="numeric">{{ projection.lastProcessedPosition }}</td>
            <td class="numeric">{{ projection.headPosition }}</td>
            <td class="numeric">{{ projection.lag }}</td>
            <td>{{ formatDateTime(projection.updatedAt) }}</td>
            <td>
              <button class="btn-link" :disabled="rebuilding" @click="rebuild(projection.name)">
                neu aufbauen
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <p class="state note">
      Der Rückstand zählt nur die Events, die <em>diese</em> Projektion konsumiert — eine
      Tippreihen-Projektion hinkt nicht hinterher, weil eine Gebühr gebucht wurde. Ein
      Neuaufbau läuft synchron und zieht die abhängigen Lesemodelle mit; die Antwort ist
      der Zustand danach, keine Bestätigung.
    </p>

    <div v-if="rebuildError" class="state error">{{ rebuildError }}</div>
    <div v-else-if="rebuilt.length" class="state success">
      Neu aufgebaut: {{ rebuilt.map(entry => entry.name).join(', ') }}
    </div>
  </div>

  <!-- OPS-01 -->
  <div class="card section">
    <h3>Verarbeitungsstand eines Commands</h3>
    <div class="field-inline">
      <div class="field grow">
        <label for="commandId">Command-ID</label>
        <input id="commandId" v-model="commandId" placeholder="UUID aus der CommandResponse">
      </div>
      <button class="btn-primary" :disabled="!commandId || commandStatus.loading" @click="loadCommand">
        Nachsehen
      </button>
    </div>

    <div v-if="commandStatus.loading" class="state loading">Wird geladen …</div>
    <div v-else-if="commandStatus.error" class="state error">{{ commandStatus.error }}</div>

    <dl v-else-if="commandStatus.data" class="facts">
      <dt>Typ</dt>
      <dd>{{ commandStatus.data.commandType }}</dd>

      <dt>Status</dt>
      <dd><span class="badge" :class="commandStatus.data.status">{{ commandStatus.data.status }}</span></dd>

      <dt>Aggregat</dt>
      <dd>{{ commandStatus.data.aggregateType ?? '–' }} {{ commandStatus.data.aggregateId ?? '' }}</dd>

      <dt>Ressource</dt>
      <dd>{{ commandStatus.data.resourceId ?? '–' }}</dd>

      <dt>Ursprünglicher Status</dt>
      <dd>{{ commandStatus.data.httpStatus ?? '–' }}</dd>

      <dt>Angenommen</dt>
      <dd>{{ formatDateTime(commandStatus.data.acceptedAt) }}</dd>

      <dt>Abgeschlossen</dt>
      <dd>{{ formatDateTime(commandStatus.data.completedAt) }}</dd>

      <dt>Lesemodelle aktuell</dt>
      <dd>{{ commandStatus.data.projectionsUpToDate ? 'ja' : 'nein' }}</dd>

      <template v-if="commandStatus.data.error">
        <dt>Fehler</dt>
        <dd>{{ commandStatus.data.error.message }}</dd>
      </template>
    </dl>

    <p class="state note">
      Die Schreibseite läuft synchron: Wer die 202 in Händen hält, hat einen Command, der
      bereits <code>completed</code> ist. Nützlich ist der Endpunkt dort, wo eine
      Wiederholung nachschlägt, was der erste Versuch erzeugt hat.
    </p>
  </div>

  <!-- OPS-03 -->
  <div class="card section">
    <h3>Event-Historie eines Aggregats</h3>
    <div class="field-inline">
      <div class="field">
        <label for="aggregateType">Typ</label>
        <select id="aggregateType" v-model="audit.aggregateType">
          <option v-for="type in AGGREGATE_TYPES" :key="type" :value="type">{{ type }}</option>
        </select>
      </div>
      <div class="field">
        <label for="aggregateId">ID</label>
        <input id="aggregateId" v-model="audit.aggregateId" type="number" min="1">
      </div>
      <button class="btn-primary" :disabled="!audit.aggregateId || trail.loading" @click="loadTrail">
        Anzeigen
      </button>
    </div>

    <div v-if="trail.loading" class="state loading">Wird geladen …</div>
    <div v-else-if="trail.error" class="state empty">{{ trail.error }}</div>

    <template v-else-if="trail.data">
      <p class="subtitle">
        Stream <code>{{ trail.data.streamId }}</code>, Version {{ trail.data.version }}
      </p>

      <div v-for="event in trail.data.events" :key="event.eventId" class="event">
        <div class="page-header">
          <div>
            <strong>{{ event.eventType }}</strong>
            <p class="subtitle">
              Version {{ event.version }} · Position {{ event.position }} ·
              {{ formatDateTime(event.occurredAt) }}
            </p>
          </div>
        </div>
        <pre class="json">{{ JSON.stringify(event.data, null, 2) }}</pre>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/services/api'
import { useQuery } from '@/composables/useCommand'
import { apiMessage } from '@/services/errors'
import { formatDateTime } from '@/support/format'

// The same list the audit handler checks against - a typo would otherwise be
// answered with "no history" instead of "no such type".
const AGGREGATE_TYPES = [
  'participant',
  'tipp_year',
  'bet_period',
  'bet_row',
  'ticket',
  'draw',
  'fee'
]

const route = useRoute()

const projections = useQuery()
const commandStatus = useQuery()
const trail = useQuery()

const commandId = ref('')
const audit = reactive({ aggregateType: 'bet_row', aggregateId: '' })

const rebuilding = ref(false)
const rebuilt = ref([])
const rebuildError = ref(null)

const projectionList = computed(() => projections.data?.projections ?? [])

const loadProjections = () => projections.load(() => api.admin.getProjections())
const loadCommand = () => commandStatus.load(() => api.getCommandStatus(commandId.value))
const loadTrail = () =>
  trail.load(() => api.admin.getAuditTrail(audit.aggregateType, audit.aggregateId))

// Not a command and therefore without an idempotency key: a rebuild changes no
// domain state, and logging it as one would put it in the command history.
async function rebuild(name) {
  rebuilding.value = true
  rebuildError.value = null
  rebuilt.value = []

  try {
    const { data } = await api.admin.rebuildProjection(name)
    rebuilt.value = data.rebuilt ?? []
    await loadProjections()
  } catch (e) {
    rebuildError.value = apiMessage(e)
  } finally {
    rebuilding.value = false
  }
}

onMounted(() => {
  loadProjections()

  // Arrived here from a command's answer elsewhere in the app.
  if (typeof route.query.commandId === 'string') {
    commandId.value = route.query.commandId
    loadCommand()
  }
})
</script>

<style scoped>
.field.grow {
  flex: 1;
  min-width: 20rem;
}

.event {
  border-top: 1px solid var(--gray-100);
  padding-top: 1rem;
  margin-top: 1rem;
}
</style>
