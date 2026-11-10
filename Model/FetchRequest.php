<?php

namespace Omnicrawl\Model;

use Omnicrawl\Agent;

/**
 * What a provider is asked to fetch, once the gate admitted it: the
 * address, whether to render it with JavaScript, and the family's agent,
 * whose User-Agent the provider sends to the target (Agent::header()).
 * A provider that follows redirects itself, hop by hop, asks follows()
 * before each: the gate's answer for that address, so that a redirect into
 * a refused address is never requested (a service that follows them on
 * its side cannot ask; the gate refuses its answer afterwards).
 */
final readonly class FetchRequest
{
    /** @param ?\Closure(string): bool $follows */
    public function __construct(
        public string $url,
        public Agent $agent,
        public bool $render = false,
        private ?\Closure $follows = null,
    ) {
    }

    /** Whether a redirect to this address may be followed: the gate admits it. */
    public function follows(string $url): bool
    {
        return null === $this->follows || true === ($this->follows)($url);
    }
}
