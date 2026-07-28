<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Auth;

use Exception;

/**
 * Reads a Keycloak access token.
 *
 * The signature check itself lives in TokenVerifier; what is left here is the
 * realm-specific part - which claim carries the participant id, where the roles
 * are, what counts as a username. Those are Keycloak's conventions, not JWT's.
 */
final class KeycloakService
{
    public function __construct(
        private TokenVerifier $verifier,
        private string $keycloakUrl = 'http://keycloak:8080',
        private string $realm = 'betting-game'
    ) {
        $this->keycloakUrl = rtrim($keycloakUrl, '/');
    }

    /**
     * Verified claims, or an exception saying why not.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidTokenException   the token is refused
     * @throws KeyUnavailableException we cannot judge it right now
     */
    public function verifyToken(string $token): array
    {
        return $this->verifier->verify($token);
    }

    /**
     * Get user info from Keycloak
     *
     * @return array<string, mixed>|null
     */
    public function getUserInfo(string $token): ?array
    {
        try {
            $url = "{$this->keycloakUrl}/realms/{$this->realm}/protocol/openid-connect/userinfo";

            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer {$token}"
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200 || !is_string($response)) {
                return null;
            }

            return $this->decodeJsonObject($response);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Extract participant ID from token
     *
     * @param array<string, mixed> $tokenData
     */
    public function getParticipantId(array $tokenData): ?int
    {
        // Try custom claim first
        $participantId = $tokenData['participant_id'] ?? null;

        if (is_int($participantId)) {
            return $participantId;
        }

        if (is_string($participantId) && preg_match('/^\d+$/', $participantId) === 1) {
            return (int) $participantId;
        }

        // Fallback: in production, map the Keycloak subject to the participant table
        return null;
    }

    /**
     * Extract username from token
     *
     * @param array<string, mixed> $tokenData
     */
    public function getUsername(array $tokenData): ?string
    {
        foreach (['preferred_username', 'name', 'email'] as $claim) {
            $value = $tokenData[$claim] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Check if user has role
     *
     * @param array<string, mixed> $tokenData
     */
    public function hasRole(array $tokenData, string $role): bool
    {
        return in_array($role, $this->getRoles($tokenData), true);
    }

    /**
     * Get all user roles
     *
     * @param array<string, mixed> $tokenData
     *
     * @return list<string>
     */
    public function getRoles(array $tokenData): array
    {
        $roles = $this->rolesOf($tokenData['realm_access'] ?? null);

        $resourceAccess = $tokenData['resource_access'] ?? null;
        if (is_array($resourceAccess)) {
            foreach ($resourceAccess as $resource) {
                $roles = array_merge($roles, $this->rolesOf($resource));
            }
        }

        return array_values(array_unique($roles));
    }

    /**
     * Reads the `roles` list out of a realm_access / resource_access entry,
     * discarding anything that is not a string.
     *
     * @return list<string>
     */
    private function rolesOf(mixed $access): array
    {
        if (!is_array($access)) {
            return [];
        }

        $roles = $access['roles'] ?? null;

        if (!is_array($roles)) {
            return [];
        }

        $result = [];
        foreach ($roles as $role) {
            if (is_string($role)) {
                $result[] = $role;
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonObject(string $json): ?array
    {
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
