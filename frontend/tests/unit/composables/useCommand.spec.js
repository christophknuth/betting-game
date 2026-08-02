import { describe, it, expect, vi, beforeEach } from 'vitest'
import { useCommand, useQuery } from '@/composables/useCommand'

/**
 * OPS-02: a command carries an Idempotency-Key so a retry replays the
 * original result instead of booking twice. useCommand.js owns the one
 * decision the API cannot make for itself: whether a second call is a retry
 * of the same attempt or a brand new command. These tests pin that decision
 * down, because it is exactly the "rule that can be checked" the frontend
 * docs call out as worth testing.
 */
describe('useCommand', () => {
  beforeEach(() => {
    let counter = 0
    vi.stubGlobal('crypto', { randomUUID: () => `uuid-${++counter}` })
  })

  it('starts idle', () => {
    const command = useCommand()

    expect(command.pending).toBe(false)
    expect(command.error).toBeNull()
    expect(command.result).toBeNull()
  })

  it('mints a key lazily and clears it on success, so the next command gets a new one', async () => {
    const command = useCommand()
    const send = vi.fn().mockResolvedValue({ data: { commandId: 'c1' } })

    const first = await command.run(send)

    expect(first).toEqual({ commandId: 'c1' })
    expect(command.result).toEqual({ commandId: 'c1' })
    expect(command.error).toBeNull()
    expect(command.pending).toBe(false)
    expect(send).toHaveBeenNthCalledWith(1, 'uuid-1')

    await command.run(send)

    // A second, unrelated command must not reuse the first one's key.
    expect(send).toHaveBeenNthCalledWith(2, 'uuid-2')
  })

  it('drops the key after a response with an error status (fixable input error stays fixable)', async () => {
    const command = useCommand()
    const rejected = { response: { status: 400, data: { message: 'Invalid numbers' } } }
    const send = vi.fn().mockRejectedValueOnce(rejected).mockResolvedValueOnce({ data: { commandId: 'c2' } })

    const result = await command.run(send)

    expect(result).toBeNull()
    expect(command.error).toBe('Invalid numbers')
    expect(command.result).toBeNull()

    await command.run(send)

    // The failed attempt's key must not be reused - a corrected retry is a
    // new command, not a repeat of the rejected one.
    expect(send).toHaveBeenNthCalledWith(1, 'uuid-1')
    expect(send).toHaveBeenNthCalledWith(2, 'uuid-2')
  })

  it('keeps the key after a response-less failure, so a retry replays the same command', async () => {
    const command = useCommand()
    const networkError = new Error('Network Error') // no `.response` at all
    const send = vi.fn().mockRejectedValueOnce(networkError).mockResolvedValueOnce({ data: { commandId: 'c3' } })

    await command.run(send)

    expect(command.error).toContain('nicht erreichbar')

    await command.run(send)

    // Same key both times: the server cannot tell these two calls apart from
    // a single attempt that was retried after a dropped response.
    expect(send).toHaveBeenNthCalledWith(1, 'uuid-1')
    expect(send).toHaveBeenNthCalledWith(2, 'uuid-1')
  })

  it('reset clears error and result without touching a pending key', async () => {
    const command = useCommand()
    const send = vi.fn().mockRejectedValueOnce(new Error('Network Error'))

    await command.run(send)
    expect(command.error).not.toBeNull()

    command.reset()

    expect(command.error).toBeNull()
    expect(command.result).toBeNull()

    // The key from the failed, response-less attempt survives the reset -
    // a retry after dismissing the error message still replays it.
    await command.run(send.mockResolvedValueOnce({ data: {} }))

    expect(send).toHaveBeenNthCalledWith(2, 'uuid-1')
  })
})

describe('useQuery', () => {
  it('starts with the given initial value and no error', () => {
    const query = useQuery([])

    expect(query.data).toEqual([])
    expect(query.loading).toBe(false)
    expect(query.error).toBeNull()
  })

  it('loads data on success', async () => {
    const query = useQuery(null)
    const send = vi.fn().mockResolvedValue({ data: { amount: 42 } })

    const result = await query.load(send)

    expect(result).toEqual({ amount: 42 })
    expect(query.data).toEqual({ amount: 42 })
    expect(query.error).toBeNull()
    expect(query.loading).toBe(false)
  })

  it('falls back to the initial value on error instead of leaving stale data (B-01: 404 is an empty state, not a crash)', async () => {
    const query = useQuery([])
    await query.load(vi.fn().mockResolvedValue({ data: ['stale'] }))
    expect(query.data).toEqual(['stale'])

    const notFound = { response: { status: 404, data: { message: 'No bet row for this period' } } }
    const send = vi.fn().mockRejectedValue(notFound)

    const result = await query.load(send)

    expect(result).toBeNull()
    expect(query.data).toEqual([])
    expect(query.error).toBe('No bet row for this period')
  })

  /**
   * The message alone cannot tell the two apart, and rendering every failure
   * as an empty state made a broken server look like an empty tipp year.
   */
  it('calls a 404 empty, and everything else a fault', async () => {
    const query = useQuery(null)

    await query.load(vi.fn().mockRejectedValue({ response: { status: 404, data: {} } }))
    expect(query.status).toBe(404)
    expect(query.isEmpty()).toBe(true)

    await query.load(vi.fn().mockRejectedValue({ response: { status: 500, data: {} } }))
    expect(query.status).toBe(500)
    expect(query.isEmpty()).toBe(false)
  })

  it('calls an unreachable API a fault, not an empty answer', async () => {
    const query = useQuery(null)

    // No response at all: the request never got a status
    await query.load(vi.fn().mockRejectedValue(new Error('network down')))

    expect(query.status).toBeNull()
    expect(query.isEmpty()).toBe(false)
  })

  it('clears the status of a failed load once one succeeds', async () => {
    const query = useQuery(null)

    await query.load(vi.fn().mockRejectedValue({ response: { status: 404, data: {} } }))
    await query.load(vi.fn().mockResolvedValue({ data: { ok: true } }))

    expect(query.status).toBeNull()
    expect(query.isEmpty()).toBe(false)
  })
})
