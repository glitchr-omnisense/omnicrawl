<?php

namespace Omnicrawl\Direct;

use Omnicrawl\Agent;
use Omnicrawl\Config;
use Omnicrawl\ProviderFactory;
use Omnicrawl\ProviderInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * "direct": the family's own fetcher, over the application's HTTP client -
 * no third-party service, no key, no JavaScript. Options: user_agent (the
 * registry gives the gate's), timeout (seconds, 15), max_bytes (the most
 * of a body kept, 5 MiB).
 */
final class DirectFactory extends ProviderFactory
{
    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnicrawl.factory_name' => 'direct',
            'omnicrawl.factory_title' => 'Direct HTTP',
            'omnicrawl.required_options' => ['user_agent'],
            'timeout' => 15,
            'max_bytes' => DirectProvider::MAX_BYTES,
        ]);
    }

    protected function build(Config $c, Agent $agent): ProviderInterface
    {
        return new DirectProvider($this->http, $c->float('timeout', 15.0), $c->int('max_bytes', DirectProvider::MAX_BYTES));
    }
}
