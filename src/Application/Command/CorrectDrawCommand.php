<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

/**
 * B-28: the draw as it should have been entered.
 *
 * All three of them, every time - a correction says what is right, not what
 * changed. A partial command would leave the API guessing whether an absent
 * Superzahl means "unchanged" or "there is none", and those are different
 * facts about a draw.
 */
final class CorrectDrawCommand
{
    /** @param list<int> $numbers */
    public function __construct(
        public readonly int $drawId,
        public readonly string $drawDate,
        public readonly array $numbers,
        public readonly int $superzahl
    ) {
    }
}
