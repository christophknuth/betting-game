<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Auth;

/**
 * A key set given to us directly instead of fetched.
 *
 * For a deployment that cannot reach Keycloak at request time, and for tests,
 * which need a signature that really verifies without a network in the loop.
 * refresh() has nothing to go back to, so it returns the same set - a rotation
 * here means redeploying the configuration, which is the trade for not
 * depending on the network.
 */
final class StaticKeys implements KeySource
{
    private JwkSet $set;

    /**
     * Takes either the key set itself or a path to a file holding it.
     *
     * Both, because both are how this actually arrives: an environment
     * variable carries the JSON, while anything that mounts its configuration -
     * a Kubernetes ConfigMap, a secret, a file next to the deployment - hands
     * over a path. A key set also survives a shell better as a file than as a
     * quoted one-liner.
     *
     * @throws KeyUnavailableException if the value names a file we cannot read
     */
    public static function from(string $jwksOrPath): self
    {
        $value = trim($jwksOrPath);

        if (str_starts_with($value, '{')) {
            return new self($value);
        }

        $contents = is_file($value) ? file_get_contents($value) : false;

        if ($contents === false) {
            throw new KeyUnavailableException("the configured key set file $value cannot be read");
        }

        return new self($contents);
    }

    /** @throws KeyUnavailableException if the configured document holds no usable key */
    public function __construct(string $jwksJson)
    {
        $set = JwkSet::fromJson($jwksJson);

        if ($set->isEmpty()) {
            throw new KeyUnavailableException(
                'the configured key set contains no usable RSA signing key'
            );
        }

        $this->set = $set;
    }

    public function keys(): JwkSet
    {
        return $this->set;
    }

    public function refresh(): JwkSet
    {
        return $this->set;
    }
}
