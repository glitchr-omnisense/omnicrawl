<?php

namespace Omnicrawl;

use Omnicrawl\Exception\InvalidConfigException;
use Omnicrawl\Exception\NotSupportedException;
use Omnicrawl\Guard\Gate;
use Omnicrawl\Guard\GuardedCrawlRuns;
use Omnicrawl\Guard\GuardedExtract;
use Omnicrawl\Guard\GuardedPageFetch;
use Omnisense\Contracts\Provider\ProviderInterface as BaseProvider;
use Omnisense\Contracts\Provider\Registry as BaseRegistry;

/**
 * The application's crawling providers by name, each built once, each
 * reached through the gate:
 *
 *   $gate = new Gate(Agent::parse('ExampleBot/1.0 (+https://example.org/bot; bot@example.org)'), $http, Targets::fromFile(__DIR__.'/targets.json'));
 *   $registry = new Registry([new DirectFactory($http), new FirecrawlFactory($http)], [
 *       'preview' => ['factory' => 'direct'],
 *       'firecrawl' => ['factory' => 'firecrawl', 'options' => ['api_key' => 'FIRECRAWL_API_KEY']],
 *   ], $gate);
 *
 *   $registry->extract('preview')->extract('https://example.org/news/1')->preview();
 *   $registry->pageFetch('firecrawl')->fetch('https://docs.example.org/', render: true);
 *   $registry->crawlRuns('firecrawl')->start(['https://docs.example.org/a', 'https://docs.example.org/b']);
 *
 * pageFetch(), extract() and crawlRuns() are the only ways to records:
 * each call admitted by the gate (target, robots.txt, TDM reservation,
 * the host's limits), then by the provider's own policy, then made; what
 * comes back is checked again and made a Record with its provenance.
 * get() gives the provider itself, which answers with what the target
 * served (Model\Fetched), never a record.
 *
 * Every provider is built with the gate's User-Agent ("user_agent", given
 * when its options do not say it; one that differs is refused: the family
 * has one identity per application), and counts in the gate's limiter.
 */
final class Registry extends BaseRegistry
{
    /** @var string */
    public const PREFIX = Config::PREFIX;

    /** @var array<string, PageFetchInterface|ExtractInterface|CrawlRunsInterface> */
    private array $guarded = [];

    /**
     * @param iterable<ProviderFactoryInterface>                                                                                         $factories
     * @param array<string, array{factory: string, options?: array<string, mixed>, policy?: array<string, mixed>, usage?: array<string, mixed>}> $config
     */
    public function __construct(iterable $factories, array $config, private readonly Gate $gate)
    {
        foreach ($config as $name => $entry) {
            $agent = $entry['options']['user_agent'] ?? null;
            if (null !== $agent && (!\is_string($agent) || Agent::parse($agent)->header() !== $gate->agent()->header())) {
                throw new InvalidConfigException(\sprintf('The "%s" provider\'s user_agent differs from the gate\'s (%s): an application crawls under one identity.', $name, $gate->agent()->header()));
            }
            $config[$name]['options'] = ['user_agent' => $gate->agent()->header()] + ($entry['options'] ?? []);
        }
        parent::__construct($factories, $config, $gate->limiter());
    }

    public function gate(): Gate
    {
        return $this->gate;
    }

    public function get(string $name): ProviderInterface
    {
        $provider = parent::get($name);

        return $provider instanceof ProviderInterface ? $provider : throw new InvalidConfigException(\sprintf('The "%s" provider (%s) is no omnicrawl provider.', $name, $provider::class));
    }

    public function pageFetch(string $name): PageFetchInterface
    {
        $guarded = $this->guarded['page-fetch '.$name] ??= new GuardedPageFetch($this->contract($name, FetcherInterface::class, 'fetch pages'), $this->gate, $this->guard($name));

        return $guarded instanceof PageFetchInterface ? $guarded : throw new \LogicException('A guarded contract of another kind.');
    }

    public function extract(string $name): ExtractInterface
    {
        $guarded = $this->guarded['extract '.$name] ??= new GuardedExtract($this->contract($name, FetcherInterface::class, 'fetch pages to extract from'), $this->gate, $this->guard($name));

        return $guarded instanceof ExtractInterface ? $guarded : throw new \LogicException('A guarded contract of another kind.');
    }

    public function crawlRuns(string $name): CrawlRunsInterface
    {
        $guarded = $this->guarded['crawl-runs '.$name] ??= new GuardedCrawlRuns($this->contract($name, RunnerInterface::class, 'run crawls'), $this->gate, $this->guard($name));

        return $guarded instanceof CrawlRunsInterface ? $guarded : throw new \LogicException('A guarded contract of another kind.');
    }

    /** @return list<string> the providers keeping a contract (page-fetch, extract, crawl-runs), in the configuration's order */
    public function names(?string $contract = null): array
    {
        return parent::names($contract);
    }

    protected function invalid(string $message): \Throwable
    {
        return new InvalidConfigException($message);
    }

    protected function notSupported(BaseProvider $provider, string $name, string $capability): \Throwable
    {
        return NotSupportedException::capability($provider, $name, $capability);
    }
}
