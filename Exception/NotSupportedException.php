<?php

namespace Omnicrawl\Exception;

/** A provider asked for what it does not do: a crawl run of a fetch-only service, a rendered page from a service that cannot send the family's User-Agent with it. */
final class NotSupportedException extends \LogicException implements OmnicrawlException
{
    public static function capability(object $provider, string $name, string $capability): self
    {
        return new self(\sprintf('The "%s" provider (%s) does not %s.', $name, $provider::class, $capability));
    }

    public static function call(string $provider, string $what): self
    {
        return new self(\sprintf('%s does not %s.', $provider, $what));
    }
}
