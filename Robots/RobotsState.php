<?php

namespace Omnicrawl\Robots;

/**
 * What a host's robots.txt was when it was read (RFC 9309 §2.3.1):
 * parsed (a 2xx, its rules apply), unavailable (a 4xx: no rule, any
 * address may be fetched), unreachable (a 5xx, a network error, too many
 * redirects: complete disallow), overridden (not read: the target's
 * declaration sets it aside, with its reason).
 */
enum RobotsState: string
{
    case Parsed = 'parsed';
    case Unavailable = 'unavailable';
    case Unreachable = 'unreachable';
    case Overridden = 'overridden';
}
