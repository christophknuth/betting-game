<template>
  <ParticipantScope>
    <div class="page-header">
      <div>
        <h1>Meine Teilnahmen</h1>
        <p class="subtitle">
          Je Tippjahr alle Tippscheine — und ob die eigene Reihe darauf stand.
        </p>
      </div>
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
      v-else-if="!memberships.length"
      class="state empty"
    >
      Keine Teilnahme hinterlegt. In ein Tippjahr aufgenommen wird man vom Administrator.
    </div>

    <div
      v-for="membership in memberships"
      :key="membership.membershipId"
      class="card"
    >
      <div class="page-header">
        <div>
          <h3>{{ membership.tippYearName }}</h3>
        </div>
        <span
          class="badge"
          :class="membership.status"
        >{{ statusLabel(membership.status) }}</span>
      </div>

      <dl class="facts section">
        <dt>Beigetreten</dt>
        <dd>{{ formatDateTime(membership.joinedAt) }}</dd>

        <template v-if="membership.leftAt">
          <dt>Ausgetreten</dt>
          <dd>{{ formatDateTime(membership.leftAt) }}</dd>
        </template>
      </dl>

      <div
        v-if="!membership.tickets.length"
        class="state empty"
      >
        Für dieses Tippjahr wurde noch kein Tippschein eingereicht.
      </div>

      <div
        v-else
        class="table-wrap"
      >
        <table class="data">
          <thead>
            <tr>
              <th scope="col">
                Zeitraum
              </th>
              <th scope="col">
                Laufzeit
              </th>
              <th scope="col">
                Ziehungen
              </th>
              <th scope="col">
                Status
              </th>
              <th scope="col">
                Eigene Reihe
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="ticket in membership.tickets"
              :key="ticket.ticketId"
            >
              <!-- The period identifies the ticket; its id is a number nobody
                   here can do anything with. -->
              <td>{{ formatDate(ticket.periodStart) }} – {{ formatDate(ticket.periodEnd) }}</td>
              <!-- Leer bei Scheinen, die vor der Erfassung der Laufzeit
                   eingereicht wurden — die Ziehungen daneben stehen trotzdem. -->
              <td>{{ scheduleLabel(ticket.durationWeeks, ticket.drawDays) ?? '—' }}</td>
              <td class="numeric">
                {{ ticket.drawCount }}
              </td>
              <td>
                <span
                  class="badge"
                  :class="ticket.status"
                >{{ statusLabel(ticket.status) }}</span>
              </td>
              <td>
                <span
                  v-if="ticket.ownRowIncluded"
                  class="badge ok"
                >dabei</span>
                <span
                  v-else
                  class="badge failed"
                >nicht dabei</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <p
        v-if="hasMissedTicket(membership)"
        class="state note"
      >
        Auf den mit „nicht dabei“ markierten Scheinen fehlt die eigene Reihe — bei einem
        unterjährigen Beitritt ist das der Normalfall und keine Lücke in den Daten.
      </p>
    </div>
  </ParticipantScope>
</template>

<script setup>
import { computed, onMounted } from 'vue'
import ParticipantScope from '@/components/ParticipantScope.vue'
import api from '@/services/api'
import { useQuery } from '@/composables/useCommand'
import { useAuthStore } from '@/stores/auth'
import { formatDate, formatDateTime, statusLabel } from '@/support/format'
import { scheduleLabel } from '@/support/drawSchedule'

const authStore = useAuthStore()
const query = useQuery()

const memberships = computed(() => query.data?.memberships ?? [])

const hasMissedTicket = (membership) => membership.tickets.some(ticket => !ticket.ownRowIncluded)

onMounted(() => {
  if (authStore.participantId) {
    query.load(() => api.getMemberships(authStore.participantId))
  }
})
</script>
