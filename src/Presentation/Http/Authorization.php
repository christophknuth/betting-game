<?php

declare(strict_types=1);

namespace BettingGame\Presentation\Http;

use BettingGame\Domain\Exception\UnauthorizedAccessException;

/**
 * Who the caller is, and what that lets them reach.
 *
 * The identity comes from the token, never from the path - that distinction is
 * the whole point of B-16. Reading `participantId` out of the URL and calling
 * it the caller would make the ownership check agree with itself every time.
 */
final class Authorization
{
    public const ADMIN_ROLE = 'admin';

    /**
     * The participant the token belongs to.
     *
     * @throws UnauthorizedAccessException when the request carries no identity
     */
    public static function participantId(Request $request): int
    {
        $participantId = $request->attribute('participant_id');

        if (!is_int($participantId)) {
            throw new UnauthorizedAccessException('No participant is associated with this token');
        }

        return $participantId;
    }

    /**
     * B-16: a participant only ever reaches their own data.
     *
     * Deliberately strict - an admin does not pass here either. The admin has
     * their own endpoints, and widening this would make every participant
     * endpoint a second, quieter admin API.
     *
     * @throws UnauthorizedAccessException
     */
    public static function requireSelf(Request $request, int $participantId): void
    {
        if (self::participantId($request) !== $participantId) {
            throw new UnauthorizedAccessException('You may only access your own data');
        }
    }

    /** @return list<string> */
    public static function roles(Request $request): array
    {
        $roles = $request->attribute('roles');

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

    public static function isAdmin(Request $request): bool
    {
        return in_array(self::ADMIN_ROLE, self::roles($request), true);
    }

    /**
     * B-17: the admin area is role protected.
     *
     * @throws UnauthorizedAccessException
     */
    public static function requireAdmin(Request $request): void
    {
        if (!self::isAdmin($request)) {
            throw new UnauthorizedAccessException('Admin access required');
        }
    }
}
