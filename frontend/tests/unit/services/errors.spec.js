import { describe, it, expect } from 'vitest'
import { hasResponse, apiMessage } from '@/services/errors'

describe('hasResponse', () => {
  it('is false when the request never got a response at all', () => {
    expect(hasResponse(new Error('Network Error'))).toBe(false)
    expect(hasResponse(null)).toBe(false)
    expect(hasResponse(undefined)).toBe(false)
  })

  it('is true whenever a response arrived, whatever its status', () => {
    expect(hasResponse({ response: { status: 500 } })).toBe(true)
    expect(hasResponse({ response: { status: 200 } })).toBe(true)
  })
})

describe('apiMessage', () => {
  it('names the missing response instead of guessing whether the write happened', () => {
    expect(apiMessage(new Error('timeout'))).toContain('nicht erreichbar')
  })

  it('explains a 401 in terms of the iss-Claim rather than the raw status', () => {
    const message = apiMessage({ response: { status: 401, data: {} } })

    expect(message).toContain('iss-Claim')
    expect(message).not.toContain('401')
  })

  it('passes the domain exception message through unchanged (not axios\'s generic text)', () => {
    const message = apiMessage({
      response: { status: 409, data: { error: 'DuplicateEntryException', message: 'A row already exists for this period.' } }
    })

    expect(message).toBe('A row already exists for this period.')
  })

  it('marks a 503 as retryable without sending the user back to a Keycloak that is down', () => {
    const message = apiMessage({ response: { status: 503, data: {} } })

    expect(message).toContain('wiederholbar')
  })

  it('falls back to the bare status when the API sent no message at all', () => {
    const message = apiMessage({ response: { status: 500, data: {} } })

    expect(message).toBe('Die API hat mit 500 geantwortet.')
  })

  it('prefers the API message over the generic fallback even for otherwise-special statuses', () => {
    // 503 has its own wording, but if the API *did* send a message, that is
    // still the more specific answer and must win.
    const message = apiMessage({ response: { status: 503, data: { message: 'Maintenance until 10pm' } } })

    expect(message).toBe('Maintenance until 10pm')
  })
})
