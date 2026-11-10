<?php

namespace Omnicrawl\Model;

/**
 * On what a target's collection rests, from the strongest to the weakest:
 * the site is one's own; its owner gave written permission; its published
 * terms allow it (or, for a refusal, forbid it); nothing but its
 * robots.txt, which says what a crawler may fetch and nothing of what may
 * be done with it. Every record carries it, so that a consumer knows what
 * it may publish.
 */
enum Authority: string
{
    case OwnSite = 'own-site';
    case WrittenPermission = 'written-permission';
    case PublishedTerms = 'published-terms';
    case RobotsOnly = 'robots-only';

    /**
     * Whether it is a licence to the data itself - the site's own, or its
     * owner's written word - and not a reading of what the site publishes:
     * only a licence may set robots.txt aside, lift a TDM reservation, or
     * use a service that evades blocks by itself.
     */
    public function isLicence(): bool
    {
        return self::OwnSite === $this || self::WrittenPermission === $this;
    }
}
