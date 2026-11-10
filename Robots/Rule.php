<?php

namespace Omnicrawl\Robots;

/**
 * One allow or disallow line of a group, its path pattern normalised
 * (RFC 9309 §2.2.2: percent-encoding made comparable, "*" any octets, a
 * final "$" the end of the path). $length is the pattern's octets: the
 * longest matching rule wins.
 */
final readonly class Rule
{
    public int $length;

    private string $regex;

    public function __construct(public bool $allow, public string $pattern)
    {
        $normalised = Path::pattern($pattern);
        $this->length = \strlen($normalised);
        $anchored = str_ends_with($normalised, '$');
        $body = $anchored ? substr($normalised, 0, -1) : $normalised;
        $this->regex = '~^'.implode('.*', array_map(static fn (string $part) => preg_quote($part, '~'), explode('*', $body))).($anchored ? '\z' : '').'~s';
    }

    /** @param string $path a path and query normalised by Path::of() */
    public function matches(string $path): bool
    {
        return 1 === preg_match($this->regex, $path);
    }
}
