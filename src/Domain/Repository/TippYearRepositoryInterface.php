<?php

declare(strict_types=1);

namespace BettingGame\Domain\Repository;

use BettingGame\Domain\Model\TippYear;
use DateTimeImmutable;

/**
 * The tipp year aggregate plus the read models its own stream feeds:
 * memberships and the annual distribution.
 */
interface TippYearRepositoryInterface
{
    public function find(int $id): ?TippYear;

    public function save(TippYear $tippYear): void;

    public function nextIdentity(): int;

    /**
     * The year a given day falls into. Years must not overlap for this to be
     * unambiguous; the administrator is responsible for that.
     */
    public function findCovering(DateTimeImmutable $date): ?TippYear;

    public function findRunning(): ?TippYear;

    /** @return list<array<string, mixed>> */
    public function findAll(): array;

    /** @return list<int> */
    public function memberIds(int $tippYearId): array;

    public function isMember(int $tippYearId, int $participantId): bool;

    /**
     * B-02: the participant's memberships, newest year first.
     *
     * @return list<array<string, mixed>>
     */
    public function membershipsOf(int $participantId): array;

    /**
     * B-04: the participant's share of a year's distribution, or null while the
     * year has not been distributed yet.
     *
     * @return array<string, mixed>|null
     */
    public function payoutShareOf(int $tippYearId, int $participantId): ?array;

    /** @return array<string, mixed>|null */
    public function findPayout(int $tippYearId): ?array;
}
