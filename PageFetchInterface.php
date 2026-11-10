<?php

namespace Omnicrawl;

use Omnicrawl\Model\Record;

/**
 * One page, fetched through the gate (Registry::pageFetch()): refused
 * before any request when its target or robots.txt says no, counted
 * against the host's limits and the provider's, made a Record with its
 * provenance, signals and rights.
 */
interface PageFetchInterface
{
    /**
     * @throws Exception\RefusedException          the family will not collect it (no request was made for it)
     * @throws Exception\UsageLimitReachedException a limit of the host or the provider is reached
     */
    public function fetch(string $url, bool $render = false): Record;
}
