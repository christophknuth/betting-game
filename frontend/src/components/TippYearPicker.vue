<template>
  <div class="field">
    <label :for="id">Tippjahr</label>

    <select
      :id="id"
      v-model="selected"
      :disabled="!years.length && emptyLabel === null"
    >
      <option
        v-if="emptyLabel !== null"
        value=""
      >
        {{ emptyLabel }}
      </option>
      <option
        v-for="year in years"
        :key="year.tippYearId"
        :value="year.tippYearId"
      >
        {{ year.tippYearName }}
      </option>
    </select>

    <span
      v-if="query.loading"
      class="hint"
    >Teilnahmen werden geladen …</span>
    <span
      v-else-if="!years.length"
      class="hint"
    >Es ist noch keine Teilnahme hinterlegt.</span>
  </div>
</template>

<script setup>
import { computed, onMounted } from 'vue'
import api from '@/services/api'
import { useQuery } from '@/composables/useCommand'
import { useAuthStore } from '@/stores/auth'

/**
 * Which tipp year the page is about, chosen by name.
 *
 * Every participant view used to ask for this as a number - the tipp year's
 * primary key, in a field labelled "Tippjahr". Nobody knows that their year is
 * the 3, and nothing on the screen said so: the field was a lookup nobody
 * could perform.
 *
 * The years come from the caller's own memberships, which is both the honest
 * list (one cannot ask about a year one did not play) and the only one
 * available: there is no participant-facing endpoint that lists tipp years,
 * and asking a member which years they played is the same question.
 */
const props = defineProps({
  id: { type: String, default: 'tippYearId' },

  /**
   * Label of the "no particular year" option, where the endpoint has a
   * sensible answer without one - `alle` for a filter, `laufendes Jahr` where
   * the API picks the running one. Left out, the newest year is preselected
   * because the page needs some year to show.
   */
  emptyLabel: { type: String, default: null }
})

const selected = defineModel({ type: [Number, String], default: '' })

const authStore = useAuthStore()
const query = useQuery()

const years = computed(() => query.data?.memberships ?? [])

onMounted(async () => {
  if (!authStore.participantId) {
    return
  }

  await query.load(() => api.getMemberships(authStore.participantId))

  // The newest is the one being played, and the one a page opened without a
  // choice should be about.
  if (props.emptyLabel === null && selected.value === '') {
    selected.value = years.value.at(-1)?.tippYearId ?? ''
  }
})
</script>
