<?php

namespace Omnicrawl\Extract;

use Omnicrawl\Model\Extraction;
use Omnicrawl\Model\ExtractRequest;
use Omnicrawl\Model\Metadata;
use Omnicrawl\Model\Record;

/**
 * Structured data of a record's HTML, with no service and no network: its
 * metadata (title, meta description, canonical link, language, every Open
 * Graph property, Twitter card fields, JSON-LD blocks - a block that does
 * not decode is left out) and the fields an ExtractRequest names. A record
 * whose body is not HTML (a service that gave text) has its fields empty.
 */
final class Extractor
{
    public function extract(Record $record, ExtractRequest $request = new ExtractRequest()): Extraction
    {
        $document = new Document($record->html ? $record->body : '');
        $fields = [];
        foreach ($request->fields as $name => $selector) {
            $parsed = Selector::parse($selector);
            $values = [];
            foreach ($document->query($parsed->xpath) as $node) {
                $value = null === $parsed->attribute ? Document::text($node) : ($node instanceof \DOMElement && $node->hasAttribute($parsed->attribute) ? trim($node->getAttribute($parsed->attribute)) : null);
                if (null !== $value && '' !== $value) {
                    $values[] = $value;
                }
            }
            $fields[$name] = $values;
        }

        return new Extraction($record, self::metadata($document, $record->finalUrl), $fields);
    }

    public static function metadata(Document $document, string $base): Metadata
    {
        $title = $document->query('//title')[0] ?? null;
        $description = $document->meta(['description'])[0] ?? null;
        $canonical = null;
        foreach ($document->query('//link[@rel][@href]') as $link) {
            if ($link instanceof \DOMElement && \in_array('canonical', preg_split('~\s+~', strtolower($link->getAttribute('rel'))) ?: [], true)) {
                $canonical = self::absolute(trim($link->getAttribute('href')), $base);
                break;
            }
        }
        $html = $document->query('/html[@lang]')[0] ?? null;
        $og = [];
        foreach ($document->query('//meta[@property][@content]') as $meta) {
            if ($meta instanceof \DOMElement && str_starts_with($property = strtolower(trim($meta->getAttribute('property'))), 'og:')) {
                $value = trim($meta->getAttribute('content'));
                $og[$property][] = \in_array($property, ['og:image', 'og:image:url', 'og:url', 'og:video', 'og:audio'], true) ? self::absolute($value, $base) : $value;
            }
        }
        $twitter = [];
        foreach ($document->query('//meta[@name][@content]') as $meta) {
            if ($meta instanceof \DOMElement && str_starts_with($name = strtolower(trim($meta->getAttribute('name'))), 'twitter:')) {
                $twitter[$name] ??= trim($meta->getAttribute('content'));
            }
        }
        $jsonLd = [];
        foreach ($document->query('//script[@type="application/ld+json"]') as $script) {
            $data = json_decode(trim($script->textContent), true);
            if (\is_array($data)) {
                $jsonLd[] = $data;
            }
        }

        return new Metadata(
            null === $title ? null : (Document::text($title) ?: null),
            $description ?: null,
            $canonical,
            $html instanceof \DOMElement ? ($html->getAttribute('lang') ?: null) : null,
            $og,
            $twitter,
            $jsonLd,
        );
    }

    /** An address read in a page, resolved against the page's (absolute, scheme-relative, root-relative, relative). */
    public static function absolute(string $href, string $base): string
    {
        if ('' === $href || preg_match('~^[a-z][a-z0-9+.-]*:~i', $href)) {
            return $href;
        }
        $parts = parse_url($base);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return $href;
        }
        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        if (str_starts_with($href, '//')) {
            return $parts['scheme'].':'.$href;
        }
        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }
        $dir = preg_replace('~/[^/]*$~', '/', $parts['path'] ?? '/') ?: '/';

        return $origin.$dir.$href;
    }
}
