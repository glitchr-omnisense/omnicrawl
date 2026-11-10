<?php

namespace Omnicrawl\Model;

use Omnicrawl\Robots\RobotsState;
use Omnisense\Contracts\Policy\DeclaredBy;

/**
 * Where a record comes from and on what it was collected: the address
 * asked and the one that answered, when, through which provider, for which
 * operation, under which authority - the target's declaration (its
 * pattern, reference, date and author) or the family's default for an
 * undeclared site ($target null) - what robots.txt was then, and the
 * User-Agent the target saw. A consumer reads it before it publishes
 * anything: a record is not open data because it was fetched.
 */
final readonly class Provenance
{
    public function __construct(
        public string $sourceUrl,
        public string $finalUrl,
        public \DateTimeImmutable $fetchedAt,
        public string $provider,
        public Operation $operation,
        public Authority $authority,
        public ?string $target,
        public ?string $reference,
        public ?string $declaredAt,
        public DeclaredBy $declaredBy,
        public RobotsState $robots,
        public ?string $robotsUrl,
        public ?string $robotsOverride,
        public string $userAgent,
    ) {
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'sourceUrl' => $this->sourceUrl,
            'finalUrl' => $this->finalUrl,
            'fetchedAt' => $this->fetchedAt->format(\DATE_ATOM),
            'provider' => $this->provider,
            'operation' => $this->operation->value,
            'authority' => $this->authority->value,
            'target' => $this->target,
            'reference' => $this->reference,
            'declaredAt' => $this->declaredAt,
            'declaredBy' => $this->declaredBy->value,
            'robots' => $this->robots->value,
            'robotsUrl' => $this->robotsUrl,
            'robotsOverride' => $this->robotsOverride,
            'userAgent' => $this->userAgent,
        ];
    }
}
