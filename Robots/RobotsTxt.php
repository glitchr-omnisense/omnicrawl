<?php

namespace Omnicrawl\Robots;

/**
 * A host's robots.txt, read per RFC 9309:
 *
 * - groups of one or more "user-agent" lines and their rules; a crawler's
 *   groups are those whose product token equals its own, case-insensitively
 *   (§2.2.1), combined into one when several match; with none, the "*"
 *   groups; with no "*" group either, no rule applies;
 * - "allow" and "disallow" rules, the longest match winning - the most
 *   octets in its pattern - and an allow winning a tie (§2.2.2); "*" and
 *   "$" as §2.2.3 says; an empty disallow is no rule; /robots.txt is
 *   always allowed (§2.2.2);
 * - lines it does not know ignored, a line without a colon ignored, a
 *   comment cut at "#"; at most MAX_BYTES read (§2.5: at least 500 KiB).
 *
 * Besides the RFC: "crawl-delay" of the matching group (no RFC, read as
 * a courtesy: the gate turns it into a rate limit on the host), "sitemap"
 * lines, and "content-usage" lines (draft-ietf-aipref-attach: kept as they
 * are on the record, not enforced while the drafts are drafts).
 *
 * The family's agent has two tokens (the site's bot, then "omnicrawl"):
 * each is matched on its own, and an address is allowed only if every
 * token that has a group of its own is allowed it - a site that disallows
 * the family as a whole is obeyed even if it allows the site's bot, and
 * the other way round. The "*" groups apply when neither token has one.
 */
final readonly class RobotsTxt
{
    /** What is parsed of a robots.txt at most (RFC 9309 §2.5 asks for 500 KiB at least). */
    public const MAX_BYTES = 512000;

    /**
     * @param list<array{agents: list<string>, rules: list<Rule>, delay: ?float, usage: list<string>}> $groups
     * @param list<string>                                                                       $sitemaps
     */
    private function __construct(
        public RobotsState $state,
        private array $groups = [],
        public array $sitemaps = [],
        public ?string $url = null,
    ) {
    }

    public static function parse(string $body, ?string $url = null): self
    {
        $body = substr($body, 0, self::MAX_BYTES);
        if (str_starts_with($body, "\xEF\xBB\xBF")) {
            $body = substr($body, 3);
        }
        $groups = [];
        $sitemaps = [];
        $current = null;
        $inRules = false;
        foreach (preg_split('~\r\n|\r|\n~', $body) ?: [] as $line) {
            $line = trim((string) preg_replace('~#.*$~s', '', $line));
            if ('' === $line || !str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode(':', $line, 2));
            $key = strtolower($key);
            if ('sitemap' === $key) {
                if ('' !== $value) {
                    $sitemaps[] = $value;
                }
                continue;
            }
            if ('user-agent' === $key) {
                if (null === $current || $inRules) {
                    if (null !== $current) {
                        $groups[] = $current;
                    }
                    $current = ['agents' => [], 'rules' => [], 'delay' => null, 'usage' => []];
                    $inRules = false;
                }
                $token = preg_match('~^(\*|[A-Za-z_-]+)~', $value, $m) ? strtolower($m[1]) : null;
                if (null !== $token) {
                    $current['agents'][] = $token;
                }
                continue;
            }
            if (null === $current) {
                continue;
            }
            switch ($key) {
                case 'allow':
                case 'disallow':
                    $inRules = true;
                    if ('' !== $value) {
                        $current['rules'][] = new Rule('allow' === $key, $value);
                    }
                    break;
                case 'crawl-delay':
                    $inRules = true;
                    if (is_numeric($value) && (float) $value >= 0) {
                        $current['delay'] = max($current['delay'] ?? 0.0, (float) $value);
                    }
                    break;
                case 'content-usage':
                    $inRules = true;
                    $current['usage'][] = $value;
                    break;
            }
        }
        if (null !== $current) {
            $groups[] = $current;
        }

        return new self(RobotsState::Parsed, $groups, $sitemaps, $url);
    }

    /** A 4xx: robots.txt unavailable, any address may be fetched (RFC 9309 §2.3.1.3). */
    public static function unavailable(?string $url = null): self
    {
        return new self(RobotsState::Unavailable, url: $url);
    }

    /** A 5xx, a network error, too many redirects: complete disallow (RFC 9309 §2.3.1.4). */
    public static function unreachable(?string $url = null): self
    {
        return new self(RobotsState::Unreachable, url: $url);
    }

    /** Not read: the target's declaration sets robots.txt aside (own site, written permission), with a reason. */
    public static function overridden(): self
    {
        return new self(RobotsState::Overridden);
    }

    /**
     * Whether the tokens may fetch a path and query ("/a/b?c=d").
     *
     * @param list<string> $tokens
     */
    public function allows(string $pathAndQuery, array $tokens): bool
    {
        if (RobotsState::Unreachable === $this->state) {
            return false;
        }
        if (RobotsState::Parsed !== $this->state) {
            return true;
        }
        $path = Path::of($pathAndQuery);
        if ('/robots.txt' === $path) {
            return true;
        }
        foreach ($this->rulesFor($tokens) as $rules) {
            $best = null;
            foreach ($rules as $rule) {
                if ($rule->matches($path) && (null === $best || $rule->length > $best->length || ($rule->length === $best->length && $rule->allow && !$best->allow))) {
                    $best = $rule;
                }
            }
            if (null !== $best && !$best->allow) {
                return false;
            }
        }

        return true;
    }

    /**
     * The rule that decides, for the reports: "disallow: /private/".
     *
     * @param list<string> $tokens
     */
    public function decidingRule(string $pathAndQuery, array $tokens): ?string
    {
        $path = Path::of($pathAndQuery);
        $deciding = null;
        foreach ($this->rulesFor($tokens) as $rules) {
            $best = null;
            foreach ($rules as $rule) {
                if ($rule->matches($path) && (null === $best || $rule->length > $best->length || ($rule->length === $best->length && $rule->allow && !$best->allow))) {
                    $best = $rule;
                }
            }
            if (null !== $best && (null === $deciding || !$best->allow)) {
                $deciding = $best;
            }
        }

        return null === $deciding ? null : ($deciding->allow ? 'allow: ' : 'disallow: ').$deciding->pattern;
    }

    /**
     * The longest Crawl-delay of the groups that apply, in seconds.
     *
     * @param list<string> $tokens
     */
    public function crawlDelay(array $tokens): ?float
    {
        $delay = null;
        foreach ($this->matching($tokens) as $groups) {
            foreach ($groups as $group) {
                if (null !== $group['delay']) {
                    $delay = max($delay ?? 0.0, $group['delay']);
                }
            }
        }

        return $delay;
    }

    /**
     * The content-usage lines of the groups that apply, as written
     * (draft-ietf-aipref-attach): "train-ai=n", "/news/ train-ai=n".
     *
     * @param list<string> $tokens
     *
     * @return list<string>
     */
    public function contentUsage(array $tokens): array
    {
        $usage = [];
        foreach ($this->matching($tokens) as $groups) {
            foreach ($groups as $group) {
                array_push($usage, ...$group['usage']);
            }
        }

        return array_values(array_unique($usage));
    }

    /**
     * The rules of each token that has groups of its own, or of "*".
     *
     * @param list<string> $tokens
     *
     * @return list<list<Rule>>
     */
    private function rulesFor(array $tokens): array
    {
        return array_map(static fn (array $groups) => array_merge(...array_map(static fn (array $group) => $group['rules'], $groups)), $this->matching($tokens));
    }

    /**
     * @param list<string> $tokens
     *
     * @return list<non-empty-list<array{agents: list<string>, rules: list<Rule>, delay: ?float, usage: list<string>}>>
     */
    private function matching(array $tokens): array
    {
        $sets = [];
        foreach ($tokens as $token) {
            $groups = array_values(array_filter($this->groups, static fn (array $group) => \in_array(strtolower($token), $group['agents'], true)));
            if ([] !== $groups) {
                $sets[] = $groups;
            }
        }
        if ([] === $sets) {
            $any = array_values(array_filter($this->groups, static fn (array $group) => \in_array('*', $group['agents'], true)));
            if ([] !== $any) {
                $sets[] = $any;
            }
        }

        return $sets;
    }
}
