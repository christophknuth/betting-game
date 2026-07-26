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

        // Validate token
        $tokenData = $this->keycloakService->validateToken($token);
        
        if (!$tokenData) {
            $this->logger->warning('Invalid or expired token');
            return JsonResponse::unauthorized('Invalid or expired token');
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
        $roles = $request->attribute('roles') ?? [];
        
        if (!in_array($requiredRole, $roles)) {
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
     */
    public function handleOptional(Request $request): void
    {
        $authHeader = $request->header('Authorization');
        
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return;
        }

        $token = substr($authHeader, 7);
        $tokenData = $this->keycloakService->validateToken($token);
        
        if ($tokenData) {
            $participantId = $this->keycloakService->getParticipantId($tokenData);
            $username = $this->keycloakService->getUsername($tokenData);
            $roles = $this->keycloakService->getRoles($tokenData);

            $request->setAttribute('authenticated', true);
            $request->setAttribute('token_data', $tokenData);
            $request->setAttribute('participant_id', $participantId);
            $request->setAttribute('username', $username);
            $request->setAttribute('roles', $roles);
            $request->setAttribute('subject', $tokenData['sub'] ?? null);
        }
    }
}
