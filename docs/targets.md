# Declaring a target

A target is a site, or part of one, with the rules the application collects it under. It is data:
a JSON file the application keeps beside its configuration, read by `Targets::fromFile()`,
checked against `resources/target.schema.json` and by `Target` at load time.

```json
{
    "$schema": "vendor/omnisense/omnicrawl/resources/target.schema.json",
    "targets": [
        {
            "target": "www.example.org",
            "collection": "allowed",
            "authority": "own-site",
            "reference": "https://www.example.org",
            "date": "2026-11-10",
            "operations": ["page-fetch", "extract", "crawl-runs"],
            "rateLimits": [{"max": 2, "window": "second"}],
            "rights": {"storage": "allowed", "redistribution": "allowed", "caching": {"permission": "allowed", "maxTtl": null}}
        },
        {
            "target": "catalogue.partner.example.com",
            "collection": "allowed",
            "authority": "written-permission",
            "reference": "Partnership agreement of 2026-08-01, clause 7 (archived as DOC-1432)",
            "date": "2026-08-01",
            "operations": ["page-fetch", "extract", "crawl-runs"],
            "render": true,
            "rateLimits": [{"max": 30, "window": "minute"}, {"max": 2000, "window": "day"}],
            "rights": {"storage": "allowed", "redistribution": "forbidden"}
        },
        {
            "target": "*.opendata.example.net",
            "collection": "allowed",
            "authority": "published-terms",
            "reference": "https://opendata.example.net/terms#reuse",
            "date": "2026-11-10",
            "operations": ["page-fetch", "extract", "crawl-runs"],
            "rights": {"storage": "allowed", "redistribution": "allowed", "licence": "etalab-2.0", "attribution": {"required": true, "text": "Example open data", "url": "https://opendata.example.net", "where": ["display"]}}
        },
        {
            "target": "timetables.example.jp",
            "collection": "refused",
            "authority": "published-terms",
            "reference": "https://timetables.example.jp/terms",
            "date": "2026-11-10",
            "reason": "Its terms forbid collecting its content by automated means."
        }
    ]
}
```

```php
$gate = new Gate(
    Agent::parse('ExampleBot/1.0 (+https://www.example.org/bot; bot@example.org)'),
    $http,                                  // any HttpClientInterface: robots.txt is read with it
    Targets::fromFile(__DIR__.'/targets.json'),
    $store,                                 // optional: a StoreInterface over the application's cache
    $limiter,                               // optional: a shared RateLimiterInterface
);
```

## Fields

| Field | Required | Meaning |
|---|---|---|
| `target` | yes | `example.org` (that host), `*.example.org` (its subdomains, not itself), either with a port (`example.org:8443`) or a path prefix (`example.org/news/`). Lower case; an internationalised name in its ASCII form. |
| `collection` | yes | `allowed` or `refused` |
| `authority` | yes | on what the collection rests (below) |
| `reference` | all but `robots-only` | where the authority can be checked: the terms' address, the permission's document, the site |
| `date` | with `reference` | the day the permission was given or the terms read (YYYY-MM-DD) |
| `operations` | no | `page-fetch`, `extract`, `crawl-runs`; default the first two. Crawl runs are always named, and need a reference. |
| `render` | no | JavaScript rendering allowed; default false |
| `rateLimits` | no | per host, in omnisense/contracts' shape; absent: the default's (1 every 10 s, 100 a day); `[]` (no limit): one's own site only |
| `robots` | no | `{"honour": true}` (default), or `{"honour": false, "reason": "..."}`: a licence only, the reason required |
| `rights` | no | omnisense/contracts' rights: `storage`, `redistribution`, `caching`, `commercial`, `attribution`, `licence`; unknown by default |
| `reason` | for a refusal | why it is refused |
| `notes` | no | anything else worth keeping |

## Authorities

| Authority | Means | It may |
|---|---|---|
| `own-site` | the application's operator owns the site | everything, robots.txt set aside with a reason, no limits, a service that evades blocks |
| `written-permission` | the owner said yes in writing | everything but going without limits |
| `published-terms` | the site's published terms allow it (or, for a refusal, forbid it) | the declared operations, under robots.txt and TDM reservations |
| `robots-only` | nothing but robots.txt | the declared operations under robots.txt; crawl runs only with a reference (someone read the site's invitation) |

Only a licence (`own-site`, `written-permission`) lifts a TDM reservation, sets robots.txt aside,
admits a provider that evades blocks by itself, or overrides one of the family's shipped refusals.

## Which declaration applies

The most specific pattern that covers the address: an exact host before a wildcard, a longer host
before a shorter one, a longer path prefix before a shorter one. A shipped refusal applies unless
the application's declaration for the address is a licence. With no declaration, the default.

## Limits

A declaration's limits are the host's: each host counts apart, even under a wildcard. The
provider's own limits apply as well, and the stricter wins. robots.txt's Crawl-delay adds one
request every so many seconds. A crawl run counts, for each host, the pages it holds of it before
the service is called.

## What a consumer reads on a record

`$record->provenance` (authority, reference, date, robots.txt's state...), `$record->signals`
(robots directives, TDM reservation, Content-Usage), `$record->rights`, `mayStore()`,
`mayRepublish()`. Publish nothing a record does not allow.
