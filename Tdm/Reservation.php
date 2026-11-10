<?php

namespace Omnicrawl\Tdm;

/**
 * Whether a resource's text and data mining rights are reserved, per the
 * TDM Reservation Protocol (W3C TDMRep Community Group Final Report,
 * 2024-05-10): $reserved true for "tdm-reservation: 1", false for "0",
 * null when nothing (or no valid value) says; $policy the address of the
 * licence offer that goes with it; $source where it was read
 * ("tdmrep.json", "header", "meta"). The sources are read in the
 * protocol's order - the file, then the HTTP header, then the HTML meta -
 * each later one prevailing, a missing value never resetting an earlier
 * one.
 */
final readonly class Reservation
{
    public function __construct(
        public ?bool $reserved = null,
        public ?string $policy = null,
        public ?string $source = null,
    ) {
    }

    public static function none(): self
    {
        return new self();
    }

    /**
     * A tdm-reservation value: "1" reserved, "0" not; anything else is a
     * protocol error, read as unset.
     */
    public static function of(?string $value, ?string $policy, string $source): self
    {
        $value = null === $value ? null : trim($value);

        return match ($value) {
            '1' => new self(true, null === $policy || '' === trim($policy) ? null : trim($policy), $source),
            '0' => new self(false, null, $source),
            default => self::none(),
        };
    }

    /** This one, overridden by a later source where that one says something. */
    public function then(self $later): self
    {
        return null === $later->reserved ? $this : $later;
    }

    /** @return array{reserved: ?bool, policy: ?string, source: ?string} */
    public function toArray(): array
    {
        return ['reserved' => $this->reserved, 'policy' => $this->policy, 'source' => $this->source];
    }
}
