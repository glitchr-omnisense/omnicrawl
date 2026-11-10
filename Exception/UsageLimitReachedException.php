<?php

namespace Omnicrawl\Exception;

use Omnisense\Contracts\Policy\UsageLimitReached;

/**
 * A call refused before it was made: a rate limit is reached - the target
 * host's (its declaration, its robots.txt Crawl-delay, the default for an
 * undeclared site) or the provider's own - or the provider asked to wait.
 * $retryAfter says in how many seconds to try again; $provider names
 * "host:<host>" for a target's limit, the provider otherwise.
 */
final class UsageLimitReachedException extends UsageLimitReached implements OmnicrawlException
{
}
