/**
 * A request that came back with a status, whatever the status was.
 *
 * The distinction matters wherever a command is involved: if there is no
 * response, the write may or may not have happened, and the client must not
 * pretend to know which.
 */
export function hasResponse(error) {
  return error?.response !== undefined
}

/**
 * The status the API answered with, or null when it never answered.
 *
 * Views need it to tell an answer from a breakdown: a `404` on "my bet row" is
 * the API saying there is none for this period, which is an empty state, while
 * a `500` on the same call is a fault and has to look like one.
 */
export function statusOf(error) {
  return hasResponse(error) ? error.response.status : null
}

/**
 * The message to put in front of the user.
 *
 * Every error the API produces carries `{error, message}`, and `message` is the
 * half written for a human - the domain exception's own words. Falling back to
 * the axios message would show "Request failed with status code 409", which
 * says nothing about which rule said no.
 */
export function apiMessage(error) {
  if (!hasResponse(error)) {
    return 'Die API ist nicht erreichbar. Ob der Aufruf angekommen ist, lässt sich nicht sagen.'
  }

  const { status, data } = error.response

  // The API says on purpose *that* it rejected a token, never why - naming the
  // failed check would describe the validation rules to the next forger. That
  // leaves the operator with nothing to go on, so the likeliest cause is added
  // here, on the client, where it gives nothing away.
  if (status === 401) {
    return 'Die API hat das Token abgelehnt. Ist die Anmeldung gerade erst erfolgt, '
      + 'liegt es meist nicht am Token: Dann erwartet die API einen anderen '
      + 'iss-Claim, als Keycloak ausstellt (KEYCLOAK_ISSUER gegen die URL prüfen, '
      + 'unter der der Browser Keycloak erreicht).'
  }

  if (typeof data?.message === 'string' && data.message !== '') {
    return data.message
  }

  // 503 is the one status the API gives a meaning of its own: the token is
  // fine, Keycloak just cannot confirm it right now.
  if (status === 503) {
    return 'Der Identity Provider ist nicht erreichbar. Der Aufruf ist wiederholbar.'
  }

  return `Die API hat mit ${status} geantwortet.`
}
