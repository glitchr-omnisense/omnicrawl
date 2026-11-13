# omnisense/omnicrawl

Web pages fetched and extracted under any PHP host, through crawling services or directly,
**responsible by default**: the target site's rules are applied by the family itself, before any
service is called. One contract by capability, one package per provider, as every family of the
[Omni ecosystem](https://glitchr-omnisense.github.io/omnisense/), on
[omnisense/contracts](https://github.com/glitchr-omnisense/contracts).

- **robots.txt honoured**, read by the core with the family's User-Agent and applied per RFC 9309;
  no option to ignore it, an override only for one's own site or with written permission, with a
  written reason.
- **A policy per target site, declared as data**: allowed or refused, on what authority (own
  site, written permission, published terms, robots.txt only), with a reference and a date, its
  rate limits, what may be stored and republished. A site nobody declared gets the conservative
  default: single pages for previews, one request every 10 seconds and 100 a day per host, no
  rendering, no crawl run. Each application declares the sites it refuses (the family ships no
  list of third-party sites).
- **An identified User-Agent** with a contact, required by every factory; per-host limits beside
  the provider's own, the stricter winning.
- **X-Robots-Tag, meta robots and TDMRep** reservations read and honoured.
- **Provenance on every record**: source, fetch time, provider, authority. Records are not open
  data.
- **No evasion**: none of the services' block-bypassing features is exposed.

Contracts: `page-fetch`, `extract` (metadata, Open Graph, Twitter cards, JSON-LD, selectors),
`crawl-runs`. Providers: a built-in `direct` fetcher, and
[omnicrawl/firecrawl](https://github.com/glitchr-omnisense/omnicrawl-firecrawl),
[omnicrawl/scrapingbee](https://github.com/glitchr-omnisense/omnicrawl-scrapingbee),
[omnicrawl/zyte](https://github.com/glitchr-omnisense/omnicrawl-zyte),
[omnicrawl/apify](https://github.com/glitchr-omnisense/omnicrawl-apify).

## Installation and a first call

```sh
composer require omnisense/omnicrawl
composer require symfony/http-client          # the client the gate reads robots.txt with, and the direct provider fetches with
```

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

With a real client, the targets the application declared, and a link preview:

```php
$gate = new Gate(Agent::parse('ExampleBot/1.0 (+https://www.example.org/bot; bot@example.org)'), $http, Targets::fromFile('targets.json'));
$registry = new Registry([new DirectFactory($http)], ['preview' => ['factory' => 'direct']], $gate);

$registry->extract('preview')->extract('https://news.example.com/story')->preview();
```

Read [what omnicrawl refuses to do, and why](docs/refusals.md), then
[how to declare a target](docs/targets.md) and [the rest](docs/index.md).

MIT licence.
