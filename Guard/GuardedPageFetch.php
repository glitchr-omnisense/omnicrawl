<?php

namespace Omnicrawl\Guard;

use Omnicrawl\Exception\NotSupportedException;
use Omnicrawl\FetcherInterface;
use Omnicrawl\Model\FetchRequest;
use Omnicrawl\Model\Operation;
use Omnicrawl\Model\Record;
use Omnicrawl\PageFetchInterface;
use Omnisense\Contracts\Policy\PolicyGuard;

/**
 * A fetcher behind the gate and its own policy: the gate admits the
 * address (target, robots.txt, the host's limits), the provider's guard
 * checks its limits ("fetch"), then - only then - the provider is called;
 * the host and the provider count the request, failed or not; the gate
 * makes the record.
 */
final class GuardedPageFetch implements PageFetchInterface
{
    public function __construct(
        private readonly FetcherInterface $provider,
        private readonly Gate $gate,
        private readonly PolicyGuard $guard,
    ) {
    }

    public function fetch(string $url, bool $render = false): Record
    {
        if ($render && !$this->provider->capabilities()->rendering) {
            throw NotSupportedException::call($this->provider->getTitle(), 'render JavaScript');
        }
        $admission = $this->gate->admit($url, Operation::PageFetch, $this->provider, $render || $this->provider->capabilities()->alwaysRenders);
        $this->guard->check('fetch');
        try {
            $fetched = $this->provider->fetch(new FetchRequest($admission->url, $this->gate->agent(), $render, fn (string $next): bool => null === $this->gate->refusal($next, Operation::PageFetch, $this->provider, $render)));
        } finally {
            $this->gate->count($admission);
            $this->guard->record('fetch');
        }

        return $this->gate->record($admission, $fetched, $this->provider);
    }

    public function inner(): FetcherInterface
    {
        return $this->provider;
    }
}
