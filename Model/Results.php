<?php

namespace Omnicrawl\Model;

/**
 * A page of a run's results, as the gate let them through: the records,
 * and the pages it dropped ($refused: each with its address, its reason -
 * a RefusedException::REASON_* - and the message), so that nothing is
 * dropped silently. $next is the cursor of the following page.
 */
final readonly class Results
{
    /**
     * @param list<Record>                                              $records
     * @param list<array{url: string, reason: string, message: string}> $refused
     */
    public function __construct(
        public array $records,
        public array $refused = [],
        public ?string $next = null,
    ) {
    }
}
