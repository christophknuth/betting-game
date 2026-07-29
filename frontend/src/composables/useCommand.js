import { reactive, ref } from 'vue'
import { apiMessage, hasResponse } from '@/services/errors'

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
 * The read-side counterpart: load a query, keep loading and error next to it.
 *
 * Queries need none of the idempotency machinery above - a GET may be repeated
 * as often as one likes.
 */
export function useQuery(initial = null) {
  const data = ref(initial)
  const loading = ref(false)
  const error = ref(null)

  async function load(send) {
    loading.value = true
    error.value = null

    try {
      const response = await send()
      data.value = response.data

      return response.data
    } catch (e) {
      // A 404 is an answer, not a breakdown: "you have no bet row for this
      // period" is exactly what the view needs to say, so the API's message is
      // handed on and the caller decides how loudly to show it.
      data.value = initial
      error.value = apiMessage(e)

      return null
    } finally {
      loading.value = false
    }
  }

  return reactive({ data, loading, error, load })
}
