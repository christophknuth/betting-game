<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Auth;

use RuntimeException;

/**
 * The signing keys could not be obtained, so no token can be judged either way.
 *
 * Distinct from InvalidTokenException because the two mean opposite things to
 * the caller: a rejected token is the caller's problem and stays rejected, an
 * unreachable key set is ours and will pass once we recover. Answering 401 to
 * the second would tell every client to throw away a perfectly good token.
 */
final class KeyUnavailableException extends RuntimeException
{
}
