<?php

namespace Omnicrawl\Guard;

use Omnicrawl\Exception\NotSupportedException;
use Omnicrawl\Extract\Extractor;
use Omnicrawl\ExtractInterface;
use Omnicrawl\FetcherInterface;
use Omnicrawl\Model\Extraction;
use Omnicrawl\Model\ExtractRequest;
use Omnicrawl\Model\FetchRequest;
use Omnicrawl\Model\Operation;
use Omnisense\Contracts\Policy\PolicyGuard;

/**
 * An extraction behind the gate: admitted as "extract" (text and data
 * mining for TDMRep, so a reservation refuses it under any authority but a
 * licence), fetched through the provider ("fetch" in its policy), made a
 * record, then read by the core's Extractor - the same metadata and
 * selectors whichever service fetched the page.
 */
final class GuardedExtract implements ExtractInterface
{
    public function __construct(
        private readonly FetcherInterface $provider,
        private readonly Gate $gate,
        private readonly PolicyGuard $guard,
        private readonly Extractor $extractor = new Extractor(),
    ) {
    }

    public function extract(string $url, ExtractRequest $request = new ExtractRequest()): Extraction
    {
        if ($request->render && !$this->provider->capabilities()->rendering) {
            throw NotSupportedException::call($this->provider->getTitle(), 'render JavaScript');
        }
        $admission = $this->gate->admit($url, Operation::Extract, $this->provider, $request->render || $this->provider->capabilities()->alwaysRenders);
        $this->guard->check('fetch');
        try {
            $fetched = $this->provider->fetch(new FetchRequest($admission->url, $this->gate->agent(), $request->render, fn (string $next): bool => null === $this->gate->refusal($next, Operation::Extract, $this->provider, $request->render)));
        } finally {
            $this->gate->count($admission);
            $this->guard->record('fetch');
        }

        return $this->extractor->extract($this->gate->record($admission, $fetched, $this->provider), $request);
    }
}
