<?php

namespace Omnicrawl;

use Omnicrawl\Model\ExtractRequest;
use Omnicrawl\Model\Extraction;

/**
 * One page's structured data, through the gate (Registry::extract()): its
 * metadata (title, description, Open Graph, Twitter card, JSON-LD) and the
 * fields asked. An extraction is text and data mining for TDMRep: a site
 * that reserves it is refused unless the target is declared under a
 * licence.
 */
interface ExtractInterface
{
    /**
     * @throws Exception\RefusedException
     * @throws Exception\UsageLimitReachedException
     */
    public function extract(string $url, ExtractRequest $request = new ExtractRequest()): Extraction;
}
