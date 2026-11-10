<?php

namespace Omnicrawl\Model;

/** A page's structured data: its record (provenance and rights included), its metadata, every value of each field asked. */
final readonly class Extraction
{
    /** @param array<string, list<string>> $fields */
    public function __construct(
        public Record $record,
        public Metadata $metadata,
        public array $fields = [],
    ) {
    }

    /**
     * A link preview: title, description (none when the page asks for no
     * snippet), image, site name, the address it links to - and the
     * record's provenance, for whoever shows it.
     *
     * @return array{title: ?string, description: ?string, image: ?string, siteName: ?string, url: string, provenance: array<string, string|null>}
     */
    public function preview(): array
    {
        return [
            'title' => $this->metadata->title(),
            'description' => $this->record->signals->nosnippet() ? null : $this->metadata->description(),
            'image' => $this->metadata->image(),
            'siteName' => $this->metadata->siteName(),
            'url' => $this->metadata->canonical ?? $this->record->finalUrl,
            'provenance' => $this->record->provenance->toArray(),
        ];
    }

    public function first(string $field): ?string
    {
        return $this->fields[$field][0] ?? null;
    }
}
