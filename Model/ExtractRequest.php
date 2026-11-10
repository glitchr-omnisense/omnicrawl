<?php

namespace Omnicrawl\Model;

/**
 * What to extract of a page besides its metadata: fields by name, each a
 * selector - a CSS selector of the subset Extract\Selector reads (tag,
 * #id, .class, [attr], [attr=value], descendants, ","), or an XPath
 * expression written "xpath:..." - and, after "@", the attribute to read
 * in place of the text ("img.logo@src").
 */
final readonly class ExtractRequest
{
    /** @param array<string, string> $fields */
    public function __construct(
        public array $fields = [],
        public bool $render = false,
    ) {
    }
}
