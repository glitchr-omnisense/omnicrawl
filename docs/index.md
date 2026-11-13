# omnisense/omnicrawl

Web pages fetched and extracted under any PHP host, through crawling services or directly,
responsible by default: the target site's rules are applied by the family itself, before any
service is called.

- [What omnicrawl refuses to do, and why](refusals.md)
- [Declaring a target](targets.md)

## Installation

```sh
composer require omnisense/omnicrawl
composer require symfony/http-client          # the client the gate reads robots.txt with, and the direct provider fetches with
```

Requirements: PHP 8.2 or later with the dom, libxml and mbstring extensions; omnisense/contracts and
symfony/http-client-contracts. A provider package adds what it calls (`composer require omnicrawl/firecrawl`,
`omnicrawl/scrapingbee`, `omnicrawl/zyte` or `omnicrawl/apify`).

No account and no network for this first call: the recorded client of the Testing kit (omnisense/contracts'
RecordedHttpClient) answers for the site, robots.txt included. The built-in `direct` provider needs no account
either, only a real HTTP client and a User-Agent naming a contact; Firecrawl, ScrapingBee, Zyte and Apify each
need an account and its API key (`omnicrawl/<provider>`, `api_key` option), and ScrapingBee and Zyte a target
declared as one's own site or with written permission.

```php
require __DIR__.'/vendor/autoload.php';

use Omnicrawl\{Agent, Registry};
use Omnicrawl\Direct\DirectFactory;
use Omnicrawl\Guard\Gate;
use Omnicrawl\Testing\RecordedHttpClient;

$http = RecordedHttpClient::routes([          // the site's answers, recorded: symfony/http-client's HttpClient::create() in production
    '~^GET https://news\.example\.com/robots\.txt$~' => [200, "User-agent: *\nDisallow: /drafts/\n", ['content-type' => ['text/plain']]],
    '~^GET https://news\.example\.com/articles/1$~' => [200, '<html><head><title>Article</title><meta property="og:title" content="An article"></head></html>', ['content-type' => ['text/html']]],
]);
$gate = new Gate(Agent::parse('ExampleBot/1.0 (+https://www.example.org/bot; bot@example.org)'), $http);
$registry = new Registry([new DirectFactory($http)], ['preview' => ['factory' => 'direct']], $gate);

echo $registry->extract('preview')->extract('https://news.example.com/articles/1')->preview()['title'], "\n";   // An article
```

## In plain PHP

```php
use Omnicrawl\Agent;
use Omnicrawl\Direct\DirectFactory;
use Omnicrawl\Guard\Gate;
use Omnicrawl\Model\ExtractRequest;
use Omnicrawl\Registry;
use Omnicrawl\Target\Targets;
use Omnicrawl\Firecrawl\FirecrawlFactory;
use Symfony\Component\HttpClient\HttpClient;

$http = HttpClient::create();
$gate = new Gate(Agent::parse('ExampleBot/1.0 (+https://www.example.org/bot; bot@example.org)'), $http, Targets::fromFile(__DIR__.'/targets.json'));
$registry = new Registry([new DirectFactory($http), new FirecrawlFactory($http)], [
    'preview' => ['factory' => 'direct'],
    'docs' => ['factory' => 'firecrawl', 'options' => ['api_key' => 'FIRECRAWL_API_KEY']],
], $gate);

// A link preview: title, description, image, the address, and the record's provenance.
$preview = $registry->extract('preview')->extract('https://news.example.com/2026/11/10/story')->preview();

// One page and its structured data.
$extraction = $registry->extract('preview')->extract('https://www.example.org/products/1', new ExtractRequest([
    'price' => '.price',
    'image' => 'img.product@src',
]));
$extraction->metadata->jsonLd;            // every JSON-LD block, decoded
$extraction->fields['price'];             // every value the selector found

// A run over a set of addresses on a declared target.
$runs = $registry->crawlRuns('docs');
$run = $runs->start(['https://docs.example.org/a', 'https://docs.example.org/b']);
$runs->run($run->id)->status;             // Queued, Running, Succeeded, Failed, Cancelled
$results = $runs->results($run->id);      // ->records, ->refused (with reasons), ->next
```

Refusals are typed (`Exception\RefusedException`: `TargetRefusedException`,
`RobotsDisallowedException`, `ReservedException`, each with `$url`, `$reason`, `$reference`);
limits are `Exception\UsageLimitReachedException` with `$retryAfter` and `$provider`
(`host:<host>` for a target's limit, the provider's name otherwise). A sorted list before a run:
`$gate->refusal($url, Operation::CrawlRuns, $registry->get('docs'))`.

## Contracts

| Contract | Consumer side (through the registry) | Provider side |
|---|---|---|
| `page-fetch` | `PageFetchInterface::fetch($url, $render)`: a `Record` | `FetcherInterface::fetch(FetchRequest)`: a `Fetched` |
| `extract` | `ExtractInterface::extract($url, ExtractRequest)`: an `Extraction` | any fetcher: the core's `Extract\Extractor` reads the page |
| `crawl-runs` | `CrawlRunsInterface::start($urls)`, `run($id)`, `results($id, $cursor)`: `Results` | `RunnerInterface::start(CrawlRequest)`, `run()`, `results()`: `FetchedPage` |

The provider side returns what the target served; only the gate makes records. `Registry::get()`
gives a provider unguarded, and what it returns carries no provenance: use the guarded contracts.

## Providers

| Package | Contracts | Renders | Target headers | Notes |
|---|---|---|---|---|
| built in: `direct` | page-fetch, extract | no | yes | the application's own HTTP client; each redirect admitted before it is followed |
| `omnicrawl/firecrawl` | page-fetch, extract, crawl-runs | always | no | undeclared sites refused (the default allows no rendering) |
| `omnicrawl/scrapingbee` | page-fetch, extract | on request | yes | licensed targets only: its rotating proxies hide who collects |
| `omnicrawl/zyte` | page-fetch, extract | no | yes | licensed targets only: its ban avoidance cannot be turned off |
| `omnicrawl/apify` | crawl-runs | no | yes | Cheerio Scraper actor, raw HTML |

A provider that publishes limits per plan declares them in its `policy.json`, under `plans`
(omnisense/contracts' schema, `UsagePolicy::forPlan()`): the default is the most restrictive plan, the
`plan` option picks another (`'options' => ['api_key' => 'FIRECRAWL_API_KEY', 'plan' => 'standard']`),
an unknown plan is refused, and a site may only tighten the plan it picked. Firecrawl and
ScrapingBee declare theirs; Zyte API and Apify publish one figure for every plan.

Keys go by reference (`api_key: FIRECRAWL_API_KEY`), read from the environment by default
(`Secrets\EnvSecrets`) or from the secrets given to the factory.

## Writing a provider

Extend `ProviderFactory` (`populate()`: name, title, `required_options` with `user_agent`;
`build(Config, Agent)`), implement `FetcherInterface` and/or `RunnerInterface`, declare
`Capabilities` honestly - `rendering`, `alwaysRenders`, `headers`, and `evasion` for anything the
service does by itself to get past a block -, and the policy as `policy.json`
(`DeclaresUsagePolicyInterface`). Send `$request->agent->header()` to the target, render only when
asked, follow no link in a run, expose no evasion feature. Test with recorded answers
(`Testing\RecordedHttpClient`), the gate with `Testing\FixedFetcher`, and run `BareTest`.

## Harness

`docker/`: `docker compose run --rm omnicrawl test` (every package, recorded answers),
`live` (the gate and the direct provider against a site served by `php -S` inside the
container: never a third-party site), `bare` (plain PHP, and what PHP loaded), `phpstan` (level
max, empty baselines). It reuses the omni families' image (`docker tag
omnisense/omnical-harness omnisense/omnicrawl-harness`) and Docker's default bridge.
