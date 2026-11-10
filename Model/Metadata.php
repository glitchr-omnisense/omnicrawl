<?php

namespace Omnicrawl\Model;

/**
 * What a page says of itself in its head: its title, description,
 * canonical address, language, Open Graph properties (og:*, by property,
 * every value), Twitter card fields, and its JSON-LD blocks, decoded. The
 * preview fields (title(), description(), image()) prefer Open Graph, as
 * link previews do. A page that asks for no snippet (nosnippet) gives no
 * description through preview().
 */
final readonly class Metadata
{
    /**
     * @param array<string, list<string>> $openGraph
     * @param array<string, string>       $twitter
     * @param list<array<mixed>>          $jsonLd
     */
    public function __construct(
        public ?string $htmlTitle = null,
        public ?string $metaDescription = null,
        public ?string $canonical = null,
        public ?string $language = null,
        public array $openGraph = [],
        public array $twitter = [],
        public array $jsonLd = [],
    ) {
    }

    public function og(string $property): ?string
    {
        return $this->openGraph[$property][0] ?? null;
    }

    public function title(): ?string
    {
        return $this->og('og:title') ?? $this->twitter['twitter:title'] ?? $this->htmlTitle;
    }

    public function description(): ?string
    {
        return $this->og('og:description') ?? $this->twitter['twitter:description'] ?? $this->metaDescription;
    }

    public function image(): ?string
    {
        return $this->og('og:image') ?? $this->og('og:image:url') ?? $this->twitter['twitter:image'] ?? null;
    }

    public function siteName(): ?string
    {
        return $this->og('og:site_name');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'title' => $this->htmlTitle, 'description' => $this->metaDescription, 'canonical' => $this->canonical, 'language' => $this->language,
            'openGraph' => $this->openGraph, 'twitter' => $this->twitter, 'jsonLd' => $this->jsonLd,
        ];
    }
}
