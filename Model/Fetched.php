<?php

namespace Omnicrawl\Model;

/**
 * What a provider got from the target, as it got it - not a record yet:
 * the gate makes one of it (Record), with its provenance and the page's
 * signals. $url is the address asked, $finalUrl the one that answered
 * after redirects, $status the target's status (not the service's),
 * $headers the target's response headers by lower-case name (null when
 * the service does not report them), $body what it served (or rendered),
 * $html whether $body is the page's HTML (a service may give text or
 * Markdown only).
 */
final readonly class Fetched
{
    /** @param ?array<string, list<string>> $headers */
    public function __construct(
        public string $url,
        public string $finalUrl,
        public int $status,
        public string $body,
        public ?array $headers = null,
        public bool $rendered = false,
        public bool $html = true,
    ) {
    }

    /** A header's first value; null when it was not sent or not reported. */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    /**
     * A header's values; a header given as "a, b" in one line stays one value.
     *
     * @return list<string>
     */
    public function headers(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    /**
     * Headers as a service reports them (any case, a value or a list), by lower-case name.
     *
     * @param array<mixed> $headers
     *
     * @return array<string, list<string>>
     */
    public static function normaliseHeaders(array $headers): array
    {
        $normalised = [];
        foreach ($headers as $name => $values) {
            if (\is_array($values) && isset($values['name'], $values['value']) && \is_scalar($values['name']) && \is_scalar($values['value'])) {
                $normalised[strtolower((string) $values['name'])][] = (string) $values['value'];
                continue;
            }
            if (!\is_string($name)) {
                continue;
            }
            foreach (\is_array($values) ? $values : [$values] as $value) {
                if (\is_scalar($value)) {
                    $normalised[strtolower($name)][] = (string) $value;
                }
            }
        }

        return $normalised;
    }
}
