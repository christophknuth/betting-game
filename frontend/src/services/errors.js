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
