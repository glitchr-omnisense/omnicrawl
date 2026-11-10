<?php

namespace Omnicrawl;

use Omnicrawl\Model\CrawlRun;
use Omnicrawl\Model\Results;

/**
 * Runs over a set of addresses, through the gate (Registry::crawlRuns()):
 * every address admitted before the run starts - one refusal refuses the
 * run, and the service is not called -, each host's share counted against
 * its limits; every result checked again when it is read (its address and
 * the one that answered, the page's signals) and the refused ones listed,
 * not dropped silently.
 */
interface CrawlRunsInterface
{
    /**
     * @param list<string> $urls
     *
     * @throws Exception\RefusedException
     * @throws Exception\UsageLimitReachedException
     */
    public function start(array $urls, bool $render = false): CrawlRun;

    public function run(string $id): CrawlRun;

    public function results(string $id, ?string $cursor = null): Results;
}
