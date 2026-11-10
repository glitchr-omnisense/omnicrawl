<?php

namespace Omnicrawl;

/**
 * One crawling service, configured: the direct fetcher, Firecrawl,
 * ScrapingBee, Zyte API, Apify. What it does is its contracts -
 * FetcherInterface (page-fetch), RunnerInterface (crawl-runs) - and what
 * it gives back is what the target served (Model\Fetched), never a record:
 * records are the gate's, made through the registry, so that no answer
 * leaves the family without its provenance.
 */
interface ProviderInterface extends \Omnisense\Contracts\Provider\ProviderInterface
{
    public function getName(): string;

    public function getTitle(): string;

    public function capabilities(): Capabilities;
}
