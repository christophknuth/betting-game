<script>
/*
 * No template on purpose, and `render` says so rather than leaving Vue to
 * warn about a component without one. What this component does is watch one
 * command and send its answer to NotificationHost, which shows it at the top
 * of the screen; it stays in the form as the marker of *which* command is
 * being reported. That is a per-form decision - useCommand must not take it
 * for every caller, because some commands are reported by hand.
 *
 * A component rather than a composable because of the draws view: it creates
 * one command per draw as the list arrives, and a watcher set up there would
 * sit outside any component scope and never be cleaned up. A component
 * instance per draw owns its watchers.
 */
export default { render: () => null }
</script>

<script setup>
import { watch } from 'vue'
import { useNotificationStore } from '@/stores/notifications'

/**
 * The answer to a command, announced above the navigation.
 *
 * It used to be rendered right here, under the button that sent the command -
 * which put the answer halfway down a long page, often below the fold, and
 * left a green "Angenommen." standing next to a form somebody had moved on
 * from. The message now appears over the top bar and takes itself away again.
 *
 * The `commandId` is not in it. It is the handle for `GET /commands/{id}` and
 * genuinely useful - to whoever is reading a log, not to whoever is booking a
 * fee. It goes to the container's output instead (see Kernel::executeCommand).
 *
 * The resource id stays, but only where the caller has to act on it: after
 * creating a participant it has to be entered into the realm by hand as their
 * `participant_id`, so hiding it would cost a lookup. Everywhere else it is a
 * number nobody types.
 */
const props = defineProps({
  command: {
    type: Object,
    required: true
  },

  /** Set where a newly created id is something the caller needs. */
  showResourceId: {
    type: Boolean,
    default: false
  }
})

const notifications = useNotificationStore()

watch(
  () => props.command.error,
  message => {
    if (message) {
      notifications.error(message)
    }
  }
)

watch(
  () => props.command.result,
  result => {
    if (!result) {
      return
    }

    const id = props.showResourceId ? result.resourceId : null

    notifications.success(id ? `Angenommen. Neue ID: #${id}` : 'Angenommen.')
  }
)
</script>
