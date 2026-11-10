<?php

namespace Omnicrawl\Robots;

/**
 * Paths made comparable, as RFC 9309 §2.2.2 asks: octets outside US-ASCII
 * percent-encoded (upper-case hex), percent-encoded US-ASCII decoded - in
 * a pattern, but for "*" and "$", which stay encoded so that they are not
 * read as a wildcard or an end; in a path, "*" and "$" then encoded, so
 * that they only meet the same encoded octets. The RFC's table of examples
 * holds: "/foo/bar/%62%61%7A" matches "/foo/bar/baz", "/foo/bar/ツ"
 * matches "/foo/bar/%E3%83%84", "/foo/bar?baz=https://foo.bar" matches
 * "/foo/bar?baz=https%3A%2F%2Ffoo.bar". Decoding every US-ASCII octet
 * makes "%2F" and "/" the same: a rule then matches a little more than the
 * letter of the RFC, never less.
 */
final class Path
{
    /** A robots.txt path pattern, normalised. */
    public static function pattern(string $pattern): string
    {
        return self::normalise($pattern, true);
    }

    /** A URL's path and query ("/a/b?c=d"), normalised; "/" for an empty path. */
    public static function of(string $pathAndQuery): string
    {
        $path = self::normalise('' === $pathAndQuery ? '/' : $pathAndQuery, false);

        return strtr($path, ['*' => '%2A', '$' => '%24']);
    }

    /** The path and query of a URL, as it is matched. */
    public static function ofUrl(string $url): string
    {
        $parts = parse_url($url);
        $path = \is_array($parts) ? ($parts['path'] ?? '/') : '/';
        $query = \is_array($parts) && isset($parts['query']) ? '?'.$parts['query'] : '';

        return self::of(('' === $path ? '/' : $path).$query);
    }

    private static function normalise(string $value, bool $pattern): string
    {
        $value = (string) preg_replace_callback('~%([0-9A-Fa-f]{2})~', static function (array $m) use ($pattern): string {
            $byte = \chr((int) hexdec($m[1]));
            if (\ord($byte) < 0x21 || \ord($byte) > 0x7E || '%' === $byte || ($pattern && ('*' === $byte || '$' === $byte))) {
                return '%'.strtoupper($m[1]);
            }

            return $byte;
        }, $value);

        return (string) preg_replace_callback('~[^\x21-\x7E]~', static fn (array $m) => '%'.strtoupper(bin2hex($m[0])), $value);
    }
}
