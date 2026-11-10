<?php

namespace Omnicrawl\Tdm;

/**
 * A host's /.well-known/tdmrep.json (TDMRep §3.1): a list of rules, each a
 * "location" pattern matched against a resource's path - "*" any
 * characters, a final "$" the end, case-sensitive - with its
 * "tdm-reservation" (0 or 1) and an optional "tdm-policy". The first rule
 * that matches decides; a file that is not such a list is no file.
 */
final readonly class TdmFile
{
    /** @param list<array{location: string, reservation: string, policy: ?string}> $rules */
    private function __construct(private array $rules = [], public ?string $url = null)
    {
    }

    public static function none(): self
    {
        return new self();
    }

    public static function parse(string $json, ?string $url = null): self
    {
        $data = json_decode($json, true);
        if (!\is_array($data) || !array_is_list($data)) {
            return new self([], $url);
        }
        $rules = [];
        foreach ($data as $rule) {
            if (!\is_array($rule) || !\is_string($rule['location'] ?? null) || !isset($rule['tdm-reservation']) || !\is_scalar($rule['tdm-reservation'])) {
                continue;
            }
            $location = $rule['location'];
            if (preg_match('~^https?://[^/]+(/.*)?$~i', $location, $m)) {
                $location = $m[1] ?? '/';
            }
            $rules[] = ['location' => $location, 'reservation' => (string) $rule['tdm-reservation'], 'policy' => \is_string($rule['tdm-policy'] ?? null) ? $rule['tdm-policy'] : null];
        }

        return new self($rules, $url);
    }

    /** What the file says of a path ("/a/b?c"); none when no rule matches. */
    public function for(string $path): Reservation
    {
        foreach ($this->rules as $rule) {
            $location = $rule['location'];
            $anchored = str_ends_with($location, '$');
            $body = $anchored ? substr($location, 0, -1) : $location;
            $regex = '~^'.implode('.*', array_map(static fn (string $part) => preg_quote($part, '~'), explode('*', $body))).($anchored ? '\z' : '').'~s';
            if (1 === preg_match($regex, $path)) {
                return Reservation::of($rule['reservation'], $rule['policy'], 'tdmrep.json');
            }
        }

        return Reservation::none();
    }
}
