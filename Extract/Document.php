<?php

namespace Omnicrawl\Extract;

/**
 * A page's HTML, parsed once, forgivingly (libxml's HTML parser, its
 * warnings kept to itself, the charset read from the page or taken as
 * UTF-8), for the signals and the extraction to read.
 */
final class Document
{
    public readonly \DOMXPath $xpath;

    public function __construct(string $html)
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        if ('' !== trim($html)) {
            $prefix = 1 === preg_match('~<meta[^>]+charset~i', substr($html, 0, 4096)) ? '' : '<?xml encoding="UTF-8">';
            $dom->loadHTML($prefix.$html, \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOWARNING | \LIBXML_COMPACT);
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $this->xpath = new \DOMXPath($dom);
    }

    /**
     * The nodes an XPath expression selects; none for an expression that does not parse.
     *
     * @return list<\DOMNode>
     */
    public function query(string $expression, ?\DOMNode $context = null): array
    {
        $nodes = @$this->xpath->query($expression, $context);
        if (false === $nodes) {
            return [];
        }
        $list = [];
        foreach ($nodes as $node) {
            if ($node instanceof \DOMNode) {
                $list[] = $node;
            }
        }

        return $list;
    }

    /**
     * The content of each <meta> whose name (or property) is one of these, in lower case.
     *
     * @param list<string> $names
     *
     * @return list<string>
     */
    public function meta(array $names, string $attribute = 'name'): array
    {
        $values = [];
        foreach ($this->query('//meta[@'.$attribute.']') as $node) {
            if ($node instanceof \DOMElement && \in_array(strtolower(trim($node->getAttribute($attribute))), $names, true) && $node->hasAttribute('content')) {
                $values[] = trim($node->getAttribute('content'));
            }
        }

        return $values;
    }

    public static function text(\DOMNode $node): string
    {
        return trim((string) preg_replace('~\s+~u', ' ', $node->textContent));
    }
}
