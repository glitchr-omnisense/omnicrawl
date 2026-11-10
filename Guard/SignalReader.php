<?php

namespace Omnicrawl\Guard;

use Omnicrawl\Agent;
use Omnicrawl\Extract\Document;
use Omnicrawl\Model\Fetched;
use Omnicrawl\Model\Signals;
use Omnicrawl\Robots\RobotsTxt;
use Omnicrawl\Tdm\Reservation;

/**
 * Reads what a fetched page says of its own use:
 *
 * - X-Robots-Tag headers (each value a list of directives, possibly
 *   addressed to one crawler: "otherbot: noindex" applies to that bot
 *   only; one addressed to the site's token or to "omnicrawl", or to
 *   nobody, applies) and <meta name="robots"> or a meta named after one of
 *   the family's tokens - the directives as Google's and Bing's robots
 *   meta specifications spell them, every one kept, in lower case;
 * - tdm-reservation and tdm-policy, in the header then the meta, over
 *   what /.well-known/tdmrep.json said (TDMRep's order);
 * - Content-Usage headers and robots.txt's content-usage lines
 *   (draft-ietf-aipref-attach), as written.
 */
final class SignalReader
{
    /** Directives whose value follows a colon: not a crawler's name. */
    private const VALUED = ['unavailable_after', 'max-snippet', 'max-image-preview', 'max-video-preview'];

    public function __construct(private readonly Agent $agent)
    {
    }

    public function read(Fetched $fetched, Reservation $file, RobotsTxt $robots): Signals
    {
        $tokens = array_map('strtolower', $this->agent->tokens());
        $directives = [];
        foreach ($fetched->headers('x-robots-tag') as $value) {
            array_push($directives, ...$this->addressed($value, $tokens));
        }
        $tdm = $file->then(Reservation::of($fetched->header('tdm-reservation'), $fetched->header('tdm-policy'), 'header'));
        $document = $fetched->html && '' !== $fetched->body ? new Document($fetched->body) : null;
        if (null !== $document) {
            foreach ($document->meta(['robots', ...$tokens]) as $content) {
                array_push($directives, ...self::split($content));
            }
            $reservation = $document->meta(['tdm-reservation']);
            $policy = $document->meta(['tdm-policy']);
            $tdm = $tdm->then(Reservation::of($reservation[0] ?? null, $policy[0] ?? null, 'meta'));
        }
        $usage = [...$robots->contentUsage($this->agent->tokens()), ...$fetched->headers('content-usage')];

        return new Signals(array_values(array_unique($directives)), $tdm, array_values(array_unique($usage)), null !== $fetched->headers);
    }

    /**
     * @param list<string> $tokens
     *
     * @return list<string>
     */
    private function addressed(string $value, array $tokens): array
    {
        if (preg_match('~^\s*([A-Za-z_-]+)\s*:\s*(.*)$~s', $value, $m) && !\in_array(strtolower($m[1]), self::VALUED, true)) {
            return \in_array(strtolower($m[1]), $tokens, true) ? self::split($m[2]) : [];
        }

        return self::split($value);
    }

    /** @return list<string> */
    private static function split(string $content): array
    {
        return array_values(array_filter(array_map(static fn (string $d) => strtolower(trim($d)), explode(',', $content)), static fn (string $d) => '' !== $d));
    }
}
