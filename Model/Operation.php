<?php

namespace Omnicrawl\Model;

/**
 * What is asked of a target, as a declaration allows it and as its limits
 * count it: one page fetched, one page's structured data extracted, a run
 * over a set of addresses.
 */
enum Operation: string
{
    case PageFetch = 'page-fetch';
    case Extract = 'extract';
    case CrawlRuns = 'crawl-runs';

    /** Whether it collects in the sense of text and data mining (TDMRep): an extraction or a run, not one page shown as it is. */
    public function mines(): bool
    {
        return self::PageFetch !== $this;
    }
}
