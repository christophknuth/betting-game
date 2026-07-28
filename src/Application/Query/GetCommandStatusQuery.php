<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

/** OPS-01: the processing state of one command. */
final class GetCommandStatusQuery
{
    public function __construct(
        public readonly string $commandId
    ) {
    }
}
