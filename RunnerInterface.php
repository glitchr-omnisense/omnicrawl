<?php

namespace Omnicrawl;

use Omnicrawl\Model\CrawlRequest;
use Omnicrawl\Model\CrawlRun;
use Omnicrawl\Model\FetchedPage;

/**
 * A provider that runs over a set of addresses (the "crawl-runs"
 * contract): exactly the addresses given - no link followed, no sitemap
 * read -, one page at a time, the family's User-Agent sent with each; its
 * status, then its results by pages, each as the target served it.
 */
interface RunnerInterface extends ProviderInterface
{
    public function start(CrawlRequest $request): CrawlRun;

    public function run(string $id): CrawlRun;

    /** @param ?string $cursor the page's cursor, null for the first */
    public function results(string $id, ?string $cursor = null): FetchedPage;
}
