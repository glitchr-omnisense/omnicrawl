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
  rendering, no crawl run. The family ships its own refusals (sites whose terms forbid automated
  collection).
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

```php
$gate = new Gate(Agent::parse('ExampleBot/1.0 (+https://www.example.org/bot; bot@example.org)'), $http, Targets::fromFile('targets.json'));
$registry = new Registry([new DirectFactory($http)], ['preview' => ['factory' => 'direct']], $gate);

$registry->extract('preview')->extract('https://news.example.com/story')->preview();
```

Read [what omnicrawl refuses to do, and why](docs/refusals.md), then
[how to declare a target](docs/targets.md) and [the rest](docs/index.md).

MIT licence.
