<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Auth;

/**
 * Where the public keys come from.
 *
 * Two methods rather than one because key rotation needs both: keys() answers
 * from whatever we already hold, refresh() insists on going back to the source.
 * The verifier only reaches for the second when it meets a kid it does not
 * know, which is the one signal that our copy is out of date.
 */
interface KeySource
{
    /** @throws KeyUnavailableException when no key set can be obtained at all */
    public function keys(): JwkSet;

    /** @throws KeyUnavailableException when no key set can be obtained at all */
    public function refresh(): JwkSet;
}
