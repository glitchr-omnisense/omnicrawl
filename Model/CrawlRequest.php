<?php

namespace Omnicrawl\Model;

use Omnicrawl\Agent;

/**
 * A run a provider is asked to start, once the gate admitted every
 * address: those addresses and nothing else (no link followed, no sitemap
 * read), rendered or not, the family's agent sent with each, one page at a
 * time on each host.
 */
final readonly class CrawlRequest
{
    /** @param list<string> $urls */
    public function __construct(
        public array $urls,
        public Agent $agent,
        public bool $render = false,
    ) {
    }
}
