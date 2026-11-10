<?php

namespace Omnicrawl;

use Omnisense\Contracts\Policy\UsagePolicy;
use Omnisense\Contracts\Provider\CapabilitiesInterface;

/**
 * What a provider is, said before it is asked anything: the contracts it
 * keeps ("page-fetch", and with it "extract", which the core makes of any
 * fetched page; "crawl-runs"), whether it renders JavaScript, whether it
 * reports the target's response headers (X-Robots-Tag, tdm-reservation:
 * without them those signals are unknown on its records), whether it
 * renders every page whatever it is asked ($alwaysRenders: the gate then
 * admits each request as a rendered one), and what the
 * service does by itself to get past a site's defences and cannot be
 * turned off ($evasion: "ban-avoidance", "user-agent-override"...). A
 * provider with any evasion is used only for targets declared under a
 * licence (own site, written permission): elsewhere it would defeat the
 * very refusals the family exists to honour.
 */
final readonly class Capabilities implements CapabilitiesInterface
{
    public const PAGE_FETCH = 'page-fetch';
    public const EXTRACT = 'extract';
    public const CRAWL_RUNS = 'crawl-runs';

    private UsagePolicy $policy;

    /** @param list<string> $evasion */
    public function __construct(
        public bool $pageFetch = false,
        public bool $crawlRuns = false,
        public bool $rendering = false,
        public bool $headers = false,
        public array $evasion = [],
        ?UsagePolicy $policy = null,
        public bool $alwaysRenders = false,
    ) {
        $this->policy = $policy ?? UsagePolicy::unknown(UsagePolicy::UNNAMED);
    }

    public function policy(): UsagePolicy
    {
        return $this->policy;
    }

    public function contracts(): array
    {
        return array_keys(array_filter([self::PAGE_FETCH => $this->pageFetch, self::EXTRACT => $this->pageFetch, self::CRAWL_RUNS => $this->crawlRuns]));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['contracts' => $this->contracts(), 'rendering' => $this->rendering, 'alwaysRenders' => $this->alwaysRenders, 'headers' => $this->headers, 'evasion' => $this->evasion, 'policy' => $this->policy->toArray()];
    }
}
