<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Auth;

use Exception;

/**
 * Keycloak JWT Token Validator
 * Validates JWT tokens issued by Keycloak
 */
final class KeycloakService
{
    private string $keycloakUrl;
    private string $realm;
    /** @var array<string, mixed>|null */
    private ?array $publicKey = null;

    public function __construct(
        string $keycloakUrl = 'http://keycloak:8080',
        string $realm = 'betting-game'
    ) {
        $this->keycloakUrl = rtrim($keycloakUrl, '/');
        $this->realm = $realm;
    }

    /**
     * Validate JWT token and return decoded payload
     *
     * @return array<string, mixed>|null
     */
    public function validateToken(string $token): ?array
    {
        try {
            // Parse JWT
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return null;
            }

            [$header, $payload] = $parts;

            // Decode header and payload
            $headerData = $this->decodeJsonObject($this->base64UrlDecode($header));
            $payloadData = $this->decodeJsonObject($this->base64UrlDecode($payload));

            if ($headerData === null || $payloadData === null) {
                return null;
            }

            // Check if token is expired
            $exp = $payloadData['exp'] ?? null;
            if (is_int($exp) && $exp < time()) {
                return null;
            }

            // Verify realm
            $issuer = $payloadData['iss'] ?? null;
            if (!is_string($issuer) || !str_contains($issuer, "/realms/{$this->realm}")) {
                return null;
            }

            // In production, verify signature with Keycloak's public key
            // For now, we trust tokens in development

            return $payloadData;
        } catch (Exception) {
            return null;
        }
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
     * Get Keycloak public key (for signature verification)
     *
     * @return array<string, mixed>|null
     */
    public function getPublicKey(): ?array
    {
        if ($this->publicKey !== null) {
            return $this->publicKey;
        }

        try {
            $url = "{$this->keycloakUrl}/realms/{$this->realm}";

            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            curl_close($ch);

            if (!is_string($response)) {
                return null;
            }

            $this->publicKey = $this->decodeJsonObject($response);

            return $this->publicKey;
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

    /**
     * Base64 URL decode
     */
    private function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder !== 0) {
            $padlen = 4 - $remainder;
            $data .= str_repeat('=', $padlen);
        }

        return base64_decode(strtr($data, '-_', '+/')) ?: '';
    }
}
