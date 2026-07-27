<?php

declare(strict_types=1);

namespace BettingGame\Domain\Model;

use BettingGame\Domain\Event\DomainEvent;
use BettingGame\Domain\Event\BettingGameCreated;
use BettingGame\Domain\Event\BettingGameEnded;
use BettingGame\Domain\ValueObject\GameStatus;
use BettingGame\Domain\Exception\BusinessRuleViolationException;
use DateTimeImmutable;

final class BettingGame
{
    /** @var list<DomainEvent> */
    private array $recordedEvents = [];
    private int $version = 0;
    private DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed>|null $pointConfiguration
     * @param array<string, mixed>|null $prizeDistribution
     */
    private function __construct(
        private int $id,
        private string $name,
        private string $description,
        private int $gameTypeId,
        private DateTimeImmutable $startDate,
        private DateTimeImmutable $endDate,
        private GameStatus $status,
        private ?float $baseFee = null,
        private ?int $feePeriodDays = null,
        private ?array $pointConfiguration = null,
        private ?array $prizeDistribution = null,
        ?DateTimeImmutable $createdAt = null
    ) {
        $this->createdAt = $createdAt ?? new DateTimeImmutable();
    }

    /**
     * @param array<string, mixed>|null $pointConfiguration
     * @param array<string, mixed>|null $prizeDistribution
     */
    public static function create(
        int $id,
        string $name,
        string $description,
        int $gameTypeId,
        DateTimeImmutable $startDate,
        DateTimeImmutable $endDate,
        ?float $baseFee = null,
        ?int $feePeriodDays = null,
        ?array $pointConfiguration = null,
        ?array $prizeDistribution = null
    ): self {
        if ($endDate <= $startDate) {
            throw new BusinessRuleViolationException('End date must be after start date');
        }

        $game = new self(
            $id,
            $name,
            $description,
            $gameTypeId,
            $startDate,
            $endDate,
            new GameStatus('upcoming'),
            $baseFee,
            $feePeriodDays,
            $pointConfiguration,
            $prizeDistribution
        );

        $game->recordEvent(new BettingGameCreated(
            (string) $id,
            $name,
            $gameTypeId,
            $startDate->format('c'),
            $endDate->format('c')
        ));

        return $game;
    }

    public function end(string $reason, bool $finalizeScores = true): void
    {
        if ($this->status->isEnded()) {
            throw new BusinessRuleViolationException('Game is already ended or cancelled');
        }

        if ($this->status->value() === 'cancelled') {
            throw new BusinessRuleViolationException('Game is already ended or cancelled');
        }

        $this->status = new GameStatus('ended');
        $this->version++;

        $this->recordEvent(new BettingGameEnded(
            (string) $this->id,
            $reason,
            $finalizeScores
        ));
    }

    private function recordEvent(DomainEvent $event): void
    {
        $this->recordedEvents[] = $event;
    }

    /**
     * @return list<DomainEvent>
     */
    public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];
        return $events;
    }

    public function id(): int
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function gameTypeId(): int
    {
        return $this->gameTypeId;
    }

    public function startDate(): DateTimeImmutable
    {
        return $this->startDate;
    }

    public function endDate(): DateTimeImmutable
    {
        return $this->endDate;
    }

    public function status(): GameStatus
    {
        return $this->status;
    }

    public function baseFee(): ?float
    {
        return $this->baseFee;
    }

    public function feePeriodDays(): ?int
    {
        return $this->feePeriodDays;
    }

    /** @return array<string, mixed>|null */
    public function pointConfiguration(): ?array
    {
        return $this->pointConfiguration;
    }

    /** @return array<string, mixed>|null */
    public function prizeDistribution(): ?array
    {
        return $this->prizeDistribution;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function version(): int
    {
        return $this->version;
    }
}
