import { defineStore } from 'pinia'
import { ref } from 'vue'

/** How long a success stays up. Long enough to read, short enough not to nag. */
const SUCCESS_MS = 5000

/** Beyond this the oldest goes, so a run of writes cannot cover the page. */
const MAX_VISIBLE = 4

/**
 * The answers to writes, on their way to the top of the screen.
 *
 * They used to be rendered where the form was, which meant the answer to a
 * button appeared next to that button - halfway down a long page, and below
 * the fold as often as not. A command either worked or it did not, and that is
 * worth seeing without hunting for it.
 *
 * Only *commands* end up here. A query that fails describes what is missing
 * from the page, and belongs where that content would have been - not in a
 * banner that floats away after five seconds.
 */
export const useNotificationStore = defineStore('notifications', () => {
  const items = ref([])

  let nextId = 0
  const timers = new Map()

  function dismiss(id) {
    items.value = items.value.filter(item => item.id !== id)

    clearTimeout(timers.get(id))
    timers.delete(id)
  }

  function push(type, message) {
    const id = ++nextId

    items.value = [...items.value, { id, type, message }]

    while (items.value.length > MAX_VISIBLE) {
      dismiss(items.value[0].id)
    }

    // Only a success disappears on its own. An error that vanishes before it
    // has been read is the reason people stop trusting a banner, and this one
    // carries the rule that said no.
    if (type === 'success') {
      timers.set(id, setTimeout(() => dismiss(id), SUCCESS_MS))
    }

    return id
  }

  const success = message => push('success', message)
  const error = message => push('error', message)

  function clear() {
    items.value.forEach(item => clearTimeout(timers.get(item.id)))
    timers.clear()
    items.value = []
  }

  return { items, success, error, dismiss, clear }
})
