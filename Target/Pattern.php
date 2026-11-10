<?php

namespace Omnicrawl\Target;

use Omnicrawl\Exception\InvalidConfigException;

/**
 * A target's pattern: a host ("example.org"), every subdomain of one
 * ("*.example.org", not the host itself), either followed by a path
 * prefix ("example.org/news/"). Hosts are compared in lower case, as
 * given (an internationalised name is written in its ASCII form,
 * "xn--..."). specificity() ranks the patterns that match one address:
 * an exact host above a wildcard, a longer host suffix above a shorter
 * one, a longer path prefix above a shorter one.
 */
final class Pattern
{
    public static function check(string $pattern): void
    {
        if (Targets::ANY !== $pattern && 1 !== preg_match('~^(\*\.)?[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*(:\d+)?(/\S*)?$~', $pattern)) {
            throw new InvalidConfigException(\sprintf('The target "%s" is no host pattern: "example.org", "*.example.org" or "example.org/path/", in lower case.', $pattern));
        }
    }

    /** Whether the pattern covers a host and a path; the host may carry its port. */
    public static function matches(string $pattern, string $host, string $path): bool
    {
        [$hostPart, $pathPart] = self::split($pattern);
        $host = strtolower($host);
        $hostOk = str_starts_with($hostPart, '*.') ? str_ends_with($host, substr($hostPart, 1)) : ($host === $hostPart || (!str_contains($hostPart, ':') && preg_replace('~:\d+$~', '', $host) === $hostPart));

        return $hostOk && ('' === $pathPart || str_starts_with($path, $pathPart));
    }

    /** @return array{0: int, 1: int, 2: int} exact host first, then the host's length, then the path's */
    public static function specificity(string $pattern): array
    {
        [$hostPart, $pathPart] = self::split($pattern);

        return [str_starts_with($hostPart, '*.') ? 0 : 1, \strlen($hostPart), \strlen($pathPart)];
    }

    /** @return array{0: string, 1: string} */
    private static function split(string $pattern): array
    {
        $slash = strpos($pattern, '/');

        return false === $slash ? [$pattern, ''] : [substr($pattern, 0, $slash), substr($pattern, $slash)];
    }
}
