<?php

namespace Omnicrawl\Guard;

/** Keeps within the PHP process: a command, a worker, a test. */
final class InMemoryStore implements StoreInterface
{
    /** @var array<string, array{expires: float, value: array<string, mixed>}> */
    private array $items = [];

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /** @param ?\Closure(): float $clock the time in seconds (microtime(true) by default) */
    public function __construct(?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    public function get(string $key): ?array
    {
        $item = $this->items[$key] ?? null;

        return null !== $item && $item['expires'] > ($this->clock)() ? $item['value'] : null;
    }

    public function set(string $key, array $value, int $ttl): void
    {
        $this->items[$key] = ['expires' => ($this->clock)() + max(0, $ttl), 'value' => $value];
    }
}
