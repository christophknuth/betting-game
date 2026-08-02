import { reactive, ref } from 'vue'
import { apiMessage, hasResponse, statusOf } from '@/services/errors'

/**
 * One command form: pending state, error message, and the idempotency key.
 *
 * The key is the reason this exists. It is minted for an attempt and kept only
 * while it is still unclear whether the server did the work - that is, when the
 * request came back without a response at all. Pressing the button again then
 * repeats the *same* command, and the API replays the original answer instead
 * of booking a second time (OPS-02).
 *
 * A key is dropped as soon as any status arrives, success or failure. The API
 * keeps a failed key taken and answers a retry with 409 rather than inventing a
 * result, so reusing it after a rejected 400 would turn a fixable validation
 * error into a permanent one.
 *
 * Returned as a `reactive` object rather than loose refs: a view usually holds
 * several of these at once, and `createYear.pending` reads better there than a
 * handful of destructured names that no longer say which form they belong to.
 */
export function useCommand() {
  const pending = ref(false)
  const error = ref(null)
  const result = ref(null)

  let idempotencyKey = null

  async function run(send) {
    idempotencyKey ??= crypto.randomUUID()

    pending.value = true
    error.value = null
    result.value = null

    try {
      const response = await send(idempotencyKey)
      result.value = response.data
      idempotencyKey = null

      return response.data
    } catch (e) {
      error.value = apiMessage(e)

      if (hasResponse(e)) {
        idempotencyKey = null
      }

      return null
    } finally {
      pending.value = false
    }
  }

  function reset() {
    error.value = null
    result.value = null
  }

  return reactive({ pending, error, result, run, reset })
}

/**
 * Several commands of the same kind, sent one after another.
 *
 * The API creates one bet period and one membership per call, so a template of
 * twelve months is twelve commands. Two things that a plain loop gets wrong:
 *
 * It **stops at the first failure** instead of pushing on. If period three
 * overlaps, periods four to twelve are built on an assumption that no longer
 * holds, and finishing the run would leave a half-filled year nobody asked
 * for. What was written stays written - the run reports how far it got.
 *
 * A retry **only sends what is left**. Items already accepted are remembered,
 * so pressing the button again after fixing the cause does not attempt them a
 * second time and collect 409s for work that succeeded.
 *
 * Each item gets its own idempotency key, for the same reason one form does:
 * a key left over from one item must not answer for another (OPS-02).
 */
export function useBatch() {
  const pending = ref(false)
  const error = ref(null)
  const completed = ref(0)
  const total = ref(0)

  const keys = new Map()
  let doneUpTo = 0

  async function run(items, send) {
    pending.value = true
    error.value = null
    total.value = items.length

    try {
      for (let index = doneUpTo; index < items.length; index++) {
        completed.value = index

        if (!keys.has(index)) {
          keys.set(index, crypto.randomUUID())
        }

        try {
          await send(items[index], keys.get(index))
        } catch (e) {
          error.value = apiMessage(e)

          if (hasResponse(e)) {
            keys.delete(index)
          }

          return false
        }

        doneUpTo = index + 1
      }

      completed.value = items.length

      return true
    } finally {
      pending.value = false
    }
  }

  function reset() {
    error.value = null
    completed.value = 0
    total.value = 0
    doneUpTo = 0
    keys.clear()
  }

  return reactive({ pending, error, completed, total, run, reset })
}

/**
 * The read-side counterpart: load a query, keep loading and error next to it.
 *
 * Queries need none of the idempotency machinery above - a GET may be repeated
 * as often as one likes.
 */
export function useQuery(initial = null) {
  const data = ref(initial)
  const loading = ref(false)
  const error = ref(null)
  const status = ref(null)

  async function load(send) {
    loading.value = true
    error.value = null
    status.value = null

    try {
      const response = await send()
      data.value = response.data

      return response.data
    } catch (e) {
      // A 404 is an answer, not a breakdown: "you have no bet row for this
      // period" is exactly what the view needs to say. The status comes along
      // so the view can tell the two apart - the message alone cannot, and
      // rendering every failure as an empty state made a broken server look
      // like an empty year.
      data.value = initial
      error.value = apiMessage(e)
      status.value = statusOf(e)

      return null
    } finally {
      loading.value = false
    }
  }

  /** True where the API answered "there is none", rather than failing. */
  const isEmpty = () => status.value === 404

  return reactive({ data, loading, error, status, isEmpty, load })
}
