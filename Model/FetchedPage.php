<?php

namespace Omnicrawl\Model;

/** A page of a run's results as the provider read them; $next the cursor of the following page, null at the end. */
final readonly class FetchedPage
{
    /** @param list<Fetched> $items */
    public function __construct(public array $items, public ?string $next = null)
    {
    }
}
