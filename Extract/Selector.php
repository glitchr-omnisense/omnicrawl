<?php

namespace Omnicrawl\Extract;

use Omnicrawl\Exception\InvalidArgumentException;

/**
 * A field's selector, made an XPath expression: "xpath:<expression>" as it
 * is, or a CSS selector of this subset - a tag or "*", #id, .class,
 * [attr], [attr=value] (quoted or not), descendant (" ") and child (">")
 * combinators, groups (",") - with an optional "@attr" at the end: read
 * that attribute in place of the text ("img.logo@src").
 */
final readonly class Selector
{
    private function __construct(public string $xpath, public ?string $attribute)
    {
    }

    public static function parse(string $selector): self
    {
        $attribute = null;
        if (preg_match('~^(.*?)@([A-Za-z_:][-A-Za-z0-9_:.]*)$~s', $selector, $m) && !str_ends_with(trim($m[1]), '[')) {
            [$selector, $attribute] = [$m[1], $m[2]];
        }
        if (str_starts_with($selector, 'xpath:')) {
            return new self(substr($selector, 6), $attribute);
        }
        $groups = [];
        foreach (explode(',', $selector) as $group) {
            $groups[] = self::group(trim($group), $selector);
        }

        return new self(implode(' | ', $groups), $attribute);
    }

    private static function group(string $group, string $whole): string
    {
        if ('' === $group) {
            throw new InvalidArgumentException(\sprintf('The selector "%s" has an empty group.', $whole));
        }
        $xpath = '';
        $axis = '//';
        $rest = $group;
        while ('' !== $rest) {
            if (!preg_match('~^(\*|[A-Za-z][A-Za-z0-9-]*)?((?:#[-\w]+|\.[-\w]+|\[[-\w:]+(?:=(?:"[^"]*"|\'[^\']*\'|[^\]]*))?\])*)\s*(>)?\s*~', $rest, $m) || '' === $m[0]) {
                throw new InvalidArgumentException(\sprintf('The selector "%s" is beyond the CSS subset the family reads (tag, #id, .class, [attr], [attr=value], " ", ">", ","): write it "xpath:...".', $whole));
            }
            $tag = '' === $m[1] ? '*' : strtolower($m[1]);
            $conditions = [];
            preg_match_all('~#([-\w]+)|\.([-\w]+)|\[([-\w:]+)(?:=("[^"]*"|\'[^\']*\'|[^\]]*))?\]~', $m[2], $parts, \PREG_SET_ORDER | \PREG_UNMATCHED_AS_NULL);
            foreach ($parts as $part) {
                $id = $part[1] ?? null;
                $class = $part[2] ?? null;
                $attribute = $part[3] ?? null;
                $value = $part[4] ?? null;
                if (null !== $id) {
                    $conditions[] = '@id='.self::literal($id);
                } elseif (null !== $class) {
                    $conditions[] = 'contains(concat(" ", normalize-space(@class), " "), '.self::literal(' '.$class.' ').')';
                } elseif (null !== $attribute) {
                    $conditions[] = '@'.$attribute.(null === $value || '' === $value ? '' : '='.self::literal(trim($value, '"\'')));
                }
            }
            $xpath .= $axis.$tag.([] === $conditions ? '' : '['.implode(' and ', $conditions).']');
            $axis = '>' === ($m[3] ?? '') ? '/' : '//';
            $rest = substr($rest, \strlen($m[0]));
        }

        return $xpath;
    }

    private static function literal(string $value): string
    {
        if (!str_contains($value, '"')) {
            return '"'.$value.'"';
        }
        if (!str_contains($value, "'")) {
            return "'".$value."'";
        }

        return 'concat("'.str_replace('"', '", \'"\', "', $value).'")';
    }
}
