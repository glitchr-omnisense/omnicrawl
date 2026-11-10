<?php

namespace Omnicrawl\Guard;

/**
 * Where the gate keeps what it read of a host - its robots.txt, its
 * /.well-known/tdmrep.json - for the time they may be kept. An
 * application served by many processes gives one over its cache (a few
 * lines over PSR-16 or Symfony's cache), so that a host is not asked again
 * by each process.
 */
interface StoreInterface
{
    /** @return ?array<string, mixed> what was kept, null when nothing is or it has expired */
    public function get(string $key): ?array;

    /** @param array<string, mixed> $value */
    public function set(string $key, array $value, int $ttl): void;
}
