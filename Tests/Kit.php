<?php

namespace Omnicrawl\Tests;

use Omnicrawl\Agent;
use Omnicrawl\Capabilities;
use Omnicrawl\Guard\Gate;
use Omnicrawl\Guard\InMemoryStore;
use Omnicrawl\Registry;
use Omnicrawl\Target\Targets;
use Omnicrawl\Testing\FixedFactory;
use Omnicrawl\Testing\RecordedHttpClient;
use Omnisense\Contracts\Policy\InMemoryRateLimiter;

/** What the guardrail tests share: a site's agent, a host's files served from routes, a registry over fixed pages, a clock they move. */
final class Kit
{
    public const UA = 'ExampleBot/1.0 (+https://example.org/bot; bot@example.org)';

    public float $now = 1_800_000_000.0;

    public readonly RecordedHttpClient $http;

    public readonly FixedFactory $factory;

    public readonly Registry $registry;

    /**
     * @param array<string, array{0: int, 1: string, 2?: array<string, list<string>>}>             $files    routes for robots.txt and tdmrep.json (RecordedHttpClient::routes())
     * @param array<string, array{0: int, 1: string, 2?: array<string, list<string>>, 3?: string}> $pages    what the fixed provider serves
     * @param list<array<string, mixed>>                                                           $declared
     * @param array{options?: array<string, mixed>, policy?: array<string, mixed>, usage?: array<string, mixed>} $provider the provider's entry: policy, options
     * @param list<string>                                                                         $extra
     */
    public function __construct(array $files = [], array $pages = [], array $declared = [], ?Capabilities $capabilities = null, array $provider = [], array $extra = [], bool $shipped = true)
    {
        $this->http = RecordedHttpClient::routes($files);
        $clock = fn (): float => $this->now;
        $gate = new Gate(Agent::parse(self::UA), $this->http, new Targets($declared, $shipped), new InMemoryStore($clock), new InMemoryRateLimiter($clock), fn (): \DateTimeImmutable => (new \DateTimeImmutable())->setTimestamp((int) $this->now));
        $this->factory = new FixedFactory($pages, $capabilities ?? new Capabilities(pageFetch: true, crawlRuns: true, rendering: true, headers: true), extra: $extra);
        $this->registry = new Registry([$this->factory], ['site' => ['factory' => 'fixed'] + $provider], $gate);
    }

    /** @return list<string> the calls the provider got */
    public function calls(): array
    {
        return $this->factory->last->calls ?? [];
    }

    /** @return list<string> "GET <url>" of each request the gate made itself */
    public function gateRequests(): array
    {
        return array_map(static fn (array $r) => $r['method'].' '.$r['url'], $this->http->requests);
    }

    public static function page(string $title = 'A page', string $head = ''): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>'.$title.'</title>'.$head.'</head><body><h1>'.$title.'</h1></body></html>';
    }
}
