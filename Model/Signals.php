<?php

namespace Omnicrawl\Model;

use Omnicrawl\Tdm\Reservation;

/**
 * What the page says of its own use, read by the gate on every record:
 * the robots directives of its X-Robots-Tag headers and its
 * <meta name="robots"> (or a meta named after the family's tokens) that
 * address the family - noindex, nofollow, noarchive, nosnippet,
 * noimageindex ("none" is noindex and nofollow), every directive kept in
 * $directives, non-standard ones ("noai", "noimageai") among them -, its
 * TDM reservation (the file, the header, the meta), and its
 * Content-Usage preferences (draft-ietf-aipref-attach: the header and the
 * robots.txt lines, kept as written, not enforced). $headersSeen is false
 * when the provider does not report the target's headers: the header
 * signals are then unknown, not absent.
 */
final readonly class Signals
{
    /**
     * @param list<string> $directives
     * @param list<string> $contentUsage
     */
    public function __construct(
        public array $directives = [],
        public Reservation $tdm = new Reservation(),
        public array $contentUsage = [],
        public bool $headersSeen = true,
    ) {
    }

    public function has(string $directive): bool
    {
        $directive = strtolower($directive);

        return \in_array($directive, $this->directives, true) || ('none' !== $directive && \in_array($directive, ['noindex', 'nofollow'], true) && \in_array('none', $this->directives, true));
    }

    /** The page asks not to be indexed: a consumer lists it in no search index of its own. */
    public function noindex(): bool
    {
        return $this->has('noindex');
    }

    /** Its links are not to be followed. */
    public function nofollow(): bool
    {
        return $this->has('nofollow');
    }

    /** No copy is to be kept: the record's rights forbid storing and caching it. */
    public function noarchive(): bool
    {
        return $this->has('noarchive');
    }

    /** No snippet is to be shown of it: a preview shows its title and its link, not its description. */
    public function nosnippet(): bool
    {
        return $this->has('nosnippet');
    }

    /** @return array{directives: list<string>, tdm: array{reserved: ?bool, policy: ?string, source: ?string}, contentUsage: list<string>, headersSeen: bool} */
    public function toArray(): array
    {
        return ['directives' => $this->directives, 'tdm' => $this->tdm->toArray(), 'contentUsage' => $this->contentUsage, 'headersSeen' => $this->headersSeen];
    }
}
