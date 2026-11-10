<?php

namespace Omnicrawl\Testing;

use Omnicrawl\Agent;
use Omnicrawl\Capabilities;
use Omnicrawl\Config;
use Omnicrawl\ProviderFactory;
use Omnicrawl\ProviderInterface;

/**
 * "fixed" (or the name given): builds a FixedFetcher over the pages given;
 * $last is the one built, whose $calls a test reads. Required, as every
 * factory: user_agent.
 */
final class FixedFactory extends ProviderFactory
{
    public ?FixedFetcher $last = null;

    /**
     * @param array<string, array{0: int, 1: string, 2?: array<string, list<string>>, 3?: string}> $pages
     * @param list<string>                                                                              $extra
     */
    public function __construct(
        private readonly array $pages = [],
        private readonly Capabilities $capabilities = new Capabilities(pageFetch: true, crawlRuns: true, rendering: true, headers: true),
        private readonly string $name = 'fixed',
        private readonly array $extra = [],
    ) {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnicrawl.factory_name' => $this->name,
            'omnicrawl.factory_title' => 'Fixed pages',
            'omnicrawl.required_options' => ['user_agent'],
        ]);
    }

    protected function build(Config $c, Agent $agent): ProviderInterface
    {
        return $this->last = new FixedFetcher($this->pages, $this->capabilities, $this->name, $this->extra);
    }
}
