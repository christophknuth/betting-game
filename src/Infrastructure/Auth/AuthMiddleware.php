<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Auth;

use BettingGame\Presentation\Http\Request;
use BettingGame\Presentation\Http\JsonResponse;
use Psr\Log\LoggerInterface;

/**
 * Authentication Middleware
 * Validates JWT tokens and sets user context
 */
final class AuthMiddleware
{
    public function __construct(
        private KeycloakService $keycloakService,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Handle authentication
     * Returns null if authenticated, JsonResponse if not
     */
    public function handle(Request $request): ?JsonResponse
    {
        // Extract token from Authorization header
        $authHeader = $request->header('Authorization');

        if (!$authHeader) {
            $this->logger->info('No Authorization header present');
            return JsonResponse::unauthorized('No authorization token provided');
        }

        // Extract Bearer token
        if (!str_starts_with($authHeader, 'Bearer ')) {
            $this->logger->warning('Invalid Authorization header format', [
                'header' => substr($authHeader, 0, 20)
            ]);
            return JsonResponse::unauthorized('Invalid authorization header format');
        }

        $token = substr($authHeader, 7);

        try {
            $tokenData = $this->keycloakService->verifyToken($token);
        } catch (InvalidTokenException $e) {
            // The reason goes to the log, not to the caller: which of our
            // checks a token failed is our business, and telling an attacker
            // whether the signature or only the expiry was wrong turns the
            // endpoint into a helper for forging the next one.
            $this->logger->warning('Token rejected', ['reason' => $e->getMessage()]);

            return JsonResponse::unauthorized('Invalid or expired token');
        } catch (KeyUnavailableException $e) {
            // We cannot verify anything at all. That is an outage on our side,
            // and answering 401 would tell every client its perfectly good
            // token is bad and send them off to re-authenticate against a
            // Keycloak we already know we cannot reach.
            $this->logger->error('Cannot verify tokens', ['reason' => $e->getMessage()]);

            return JsonResponse::serviceUnavailable('Authentication is temporarily unavailable');
        }

        // Extract user information
        $participantId = $this->keycloakService->getParticipantId($tokenData);
        $username = $this->keycloakService->getUsername($tokenData);
        $roles = $this->keycloakService->getRoles($tokenData);

        // Set user context in request
        $request->setAttribute('authenticated', true);
        $request->setAttribute('token_data', $tokenData);
        $request->setAttribute('participant_id', $participantId);
        $request->setAttribute('username', $username);
        $request->setAttribute('roles', $roles);
        $request->setAttribute('subject', $tokenData['sub'] ?? null);

        $this->logger->info('User authenticated', [
            'username' => $username,
            'participant_id' => $participantId,
            'roles' => $roles
        ]);

        return null; // Authentication successful
    }

    /**
     * Check if user has required role
     */
    public function requireRole(Request $request, string $requiredRole): ?JsonResponse
    {
        $roles = $request->attribute('roles');
        $roles = is_array($roles) ? $roles : [];

        if (!in_array($requiredRole, $roles, true)) {
            $this->logger->warning('Insufficient permissions', [
                'required_role' => $requiredRole,
                'user_roles' => $roles,
                'username' => $request->attribute('username')
            ]);

            return JsonResponse::forbidden('Insufficient permissions');
        }

        return null;
    }

    /**
     * Optional authentication (doesn't fail if no token)
     *
     * A token that fails verification leaves the request anonymous. It must not
     * leave it authenticated-with-unverified-claims, which is what "optional"
     * would mean if the failure were simply ignored.
     */
    public function handleOptional(Request $request): void
    {
        $authHeader = $request->header('Authorization');

        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return;
        }

        try {
            $tokenData = $this->keycloakService->verifyToken(substr($authHeader, 7));
        } catch (InvalidTokenException | KeyUnavailableException $e) {
            $this->logger->info('Optional authentication skipped', ['reason' => $e->getMessage()]);

            return;
        }

        $request->setAttribute('authenticated', true);
        $request->setAttribute('token_data', $tokenData);
        $request->setAttribute('participant_id', $this->keycloakService->getParticipantId($tokenData));
        $request->setAttribute('username', $this->keycloakService->getUsername($tokenData));
        $request->setAttribute('roles', $this->keycloakService->getRoles($tokenData));
        $request->setAttribute('subject', $tokenData['sub'] ?? null);
    }
}
