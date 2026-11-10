<?php

namespace Omnicrawl;

use Omnicrawl\Model\FetchRequest;
use Omnicrawl\Model\Fetched;

/**
 * A provider that fetches one page (the "page-fetch" contract, and
 * "extract" with it). It is called by the registry's guarded fetch only,
 * once the gate admitted the address: it sends $request->agent's
 * User-Agent to the target, renders only when asked, follows at most five
 * redirects, and uses none of its service's evasion features.
 */
interface FetcherInterface extends ProviderInterface
{
    public function fetch(FetchRequest $request): Fetched;
}
