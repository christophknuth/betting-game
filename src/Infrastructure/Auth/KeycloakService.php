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
     */
    public function validateToken(string $token): ?array
    {
        try {
            // Parse JWT
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return null;
            }

            [$header, $payload, $signature] = $parts;

            // Decode header and payload
            $headerData = json_decode($this->base64UrlDecode($header), true);
            $payloadData = json_decode($this->base64UrlDecode($payload), true);

            if (!$headerData || !$payloadData) {
                return null;
            }

            // Check if token is expired
            if (isset($payloadData['exp']) && $payloadData['exp'] < time()) {
                return null;
            }

            // Verify realm
            if (!isset($payloadData['iss']) || 
                !str_contains($payloadData['iss'], "/realms/{$this->realm}")) {
                return null;
            }

            // In production, verify signature with Keycloak's public key
            // For now, we trust tokens in development
            
            return $payloadData;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get user info from Keycloak
     */
    public function getUserInfo(string $token): ?array
    {
        try {
            $url = "{$this->keycloakUrl}/realms/{$this->realm}/protocol/openid-connect/userinfo";
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer {$token}"
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200 || !$response) {
                return null;
            }

            return json_decode($response, true);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get Keycloak public key (for signature verification)
     */
    public function getPublicKey(): ?array
    {
        if ($this->publicKey !== null) {
            return $this->publicKey;
        }

        try {
            $url = "{$this->keycloakUrl}/realms/{$this->realm}";
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            curl_close($ch);

            if (!$response) {
                return null;
            }

            $data = json_decode($response, true);
            $this->publicKey = $data;
            
            return $this->publicKey;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Extract participant ID from token
     */
    public function getParticipantId(array $tokenData): ?int
    {
        // Try custom claim first
        if (isset($tokenData['participant_id'])) {
            return (int) $tokenData['participant_id'];
        }

        // Fallback: use sub (subject) as participant ID
        if (isset($tokenData['sub'])) {
            // In production, map Keycloak sub to participant table
            return null;
        }

        return null;
    }

    /**
     * Extract username from token
     */
    public function getUsername(array $tokenData): ?string
    {
        return $tokenData['preferred_username'] ?? 
               $tokenData['name'] ?? 
               $tokenData['email'] ?? 
               null;
    }

    /**
     * Check if user has role
     */
    public function hasRole(array $tokenData, string $role): bool
    {
        // Check realm roles
        if (isset($tokenData['realm_access']['roles']) && 
            in_array($role, $tokenData['realm_access']['roles'])) {
            return true;
        }

        // Check resource roles
        if (isset($tokenData['resource_access'])) {
            foreach ($tokenData['resource_access'] as $resource) {
                if (isset($resource['roles']) && in_array($role, $resource['roles'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get all user roles
     */
    public function getRoles(array $tokenData): array
    {
        $roles = [];

        // Realm roles
        if (isset($tokenData['realm_access']['roles'])) {
            $roles = array_merge($roles, $tokenData['realm_access']['roles']);
        }

        // Resource roles
        if (isset($tokenData['resource_access'])) {
            foreach ($tokenData['resource_access'] as $resource) {
                if (isset($resource['roles'])) {
                    $roles = array_merge($roles, $resource['roles']);
                }
            }
        }

        return array_unique($roles);
    }

    /**
     * Base64 URL decode
     */
    private function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $padlen = 4 - $remainder;
            $data .= str_repeat('=', $padlen);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
