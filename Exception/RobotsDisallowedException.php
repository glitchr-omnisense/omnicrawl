<?php

namespace Omnicrawl\Exception;

/**
 * The target's robots.txt disallows the address for the family's user
 * agent, or could not be read (a 5xx, the host unreachable: RFC 9309 says
 * to assume complete disallow then).
 */
final class RobotsDisallowedException extends RefusedException
{
}
