# What omnicrawl refuses to do, and why

Omnicrawl collects web pages for applications: a link preview, a partner's catalogue, one's own
pages archived or watched, a site that invites crawlers. It exists on one condition: **the target
site's rules are applied by the family itself**, before any crawling service is called, and not
left to each caller. A third-party scraper does not take the responsibility away from whoever
asks it to collect; a family that let any address through would only hide that responsibility.

Everything below is enforced by the core (`Omnicrawl\Guard\Gate`), proved by the guardrail tests
(`Tests/Guardrails/`), and holds whatever provider is configured.

## It refuses a site that does not allow collection

- **A target declared as refused** is refused with `TargetRefusedException` (reason
  `declared-refused`), with the reference where the refusal was read. Nothing is requested: not
  the provider, not even the site's robots.txt.
- **The family ships its own refusals** (`resources/targets/refused.json`): sites whose published
  terms forbid automated collection, each with its reference and the day it was read. The first
  is a Japanese rail operator's timetable service: Tokyu Railways' fare, route and timetable
  search, whose terms forbid collecting its content by crawlers, robots, scraping or any
  automated means, and using it to train models. A site's own declaration lifts a shipped refusal
  only under a licence (its own site, or the owner's written permission): no reading of terms or
  robots.txt turns a known refusal into a permission.
- **A site that says nothing** gets the conservative default (below), never "anything goes".

## It refuses what robots.txt disallows

robots.txt is read **by the gate itself**, with the family's User-Agent, never through the
crawling service, and applied per RFC 9309 to the family's two product tokens - the site's bot
and `omnicrawl`, so that a site can address either:

- groups matched by product token, case-insensitively; several groups of one token combined; the
  `*` groups when neither token has its own; no rule when there is no `*` group either;
- allow and disallow, the longest match winning, an allow winning a tie; `*` and `$`; percent-
  encoding normalised on both sides; `/robots.txt` always allowed; 500 KiB read at least;
- a site that disallows the family as a whole is obeyed even if it allows the site's bot, and
  the other way round;
- a 4xx is "unavailable" (crawling allowed, as the RFC says), **but a 429**, read as the server's
  own refusal; a 5xx, a network error or more than five redirects is "unreachable": complete
  disallow (`RobotsDisallowedException`, reason `robots-unreachable`);
- kept 24 hours at most (less when its Cache-Control says so, one minute at least), an
  unreachable one an hour;
- `Crawl-delay`, which no RFC defines, is honoured as a courtesy: one request every so many
  seconds on that host.

There is **no option to ignore robots.txt**. A target may set it aside only when declared as
one's own site or with the owner's written permission, and only with a written reason, which
every record then carries (`Provenance::$robotsOverride`).

## It refuses to collect more than a link preview from a site that said nothing

The default for an undeclared target (`Targets::default()`):

| | Default | Why |
|---|---|---|
| Single pages (`page-fetch`, `extract`) | allowed under robots.txt | a link preview, a title and an image, is what such a site publishes Open Graph tags for |
| Crawl runs | **refused** | a run over a site is collection, and collection needs a declaration with a reference |
| JavaScript rendering | **refused** | a preview needs none, and rendering loads every asset of the page |
| Rate | 1 request every 10 s, 100 a day, per host | a preview is one request; a hundred a day is a busy forum's links, not a harvest |
| Caching | a day at most | a preview is refreshed, not kept |
| Storing, republishing | unknown: `mayStore()` and `mayRepublish()` say no | robots.txt says what may be fetched, never what may be done with it |

An application may go further and refuse every undeclared target
(`new Targets($declared, refuseUndeclared: true)`).

## It refuses to mine what a site reserves

The TDM Reservation Protocol (W3C Community Group Final Report, 2024-05-10) is implemented:
`/.well-known/tdmrep.json` (first matching `location` wins, `*` and `$`), then the
`tdm-reservation` and `tdm-policy` headers, then the HTML meta, each later source prevailing. An
**extraction or a crawl run** is text and data mining: a reserved resource is refused
(`ReservedException`, reason `tdm-reserved`, the licence offer as its reference) unless the
target is declared under a licence. A single page fetched as it is (a preview) is not mining: its
record carries the reservation, for the consumer to honour.

## It refuses to keep what a page asks not to keep

`X-Robots-Tag` headers and `<meta name="robots">` (or a meta named after the family's tokens) are
read on every record; a directive addressed to another crawler (`otherbot: noindex`) does not
apply. `noarchive` makes the record's rights forbid storing and caching it; `nosnippet` takes the
description out of a preview; `noindex`, `nofollow` and every other directive are on the record
(`Signals`). A provider that does not report the target's headers says so
(`Signals::$headersSeen` false): those signals are then unknown, not absent.

## It refuses to hide

Every factory requires a User-Agent that names the site's bot, its version and a contact (an
address that explains the bot, or an e-mail address), checked by `Agent::parse()`; a browser's
User-Agent is refused, and `omnicrawl/1` is appended so that sites can recognise the family. An
application crawls under one identity: the registry refuses a provider configured with another.
Every provider sends it to the target.

## It refuses evasion

None of the services' features made to get past a site that blocks is exposed, and the family
says "no" to each explicitly where the service has a switch:

| Service | What the family sends | What it does not expose |
|---|---|---|
| Firecrawl | `proxy: basic`, `maxAge: 0`, `storeInCache: false` | `enhanced`/`auto` proxies (retries through anti-bot proxies), actions, location, mobile |
| ScrapingBee | `premium_proxy=false`, `stealth_proxy=false`, `transparent_status_code=true` (no retry through another proxy) | residential and stealth proxies, country, sessions, JS scenarios |
| Apify | Cheerio Scraper without Apify Proxy, `maxRequestRetries: 0`, `maxConcurrency: 1`, no link followed | proxy groups (residential), browser actors, sitemaps |
| Zyte API | HTTP mode with the family's User-Agent | browser mode (it cannot send the User-Agent), IP type, geolocation, sessions |

Why: a site that blocks a crawler is saying no. Proxy rotation to defeat a block, residential
IPs, CAPTCHA solving and fingerprint disguise exist to override that answer; the family exists to
respect it.

Zyte API avoids bans by itself - CAPTCHA handling and residential IPs are on by default and cannot
be turned off per request - and may override the User-Agent "for certain websites if needed for
ban avoidance". Its provider declares both (`Capabilities::$evasion`), and the gate uses a
provider with any evasion **for targets declared under a licence only** (one's own site, written
permission): `TargetRefusedException`, reason `provider-not-allowed`, everywhere else.

Firecrawl renders every page in a browser, with no switch to turn it off: it declares
`alwaysRenders`, and the gate admits it only where rendering is allowed.

## It refuses to stretch a run

A crawl run is over **exactly the addresses given**: no link followed, no sitemap read, one page
at a time on the service's side. Every address is admitted before the service is called - the
first refusal refuses the run -, each host's share is checked against its limits, and every
result is checked again when it is read: a page the service brings back that was not admitted
(robots.txt, the target's operations, a TDM reservation in the page) is dropped and listed with
its reason (`Results::$refused`), never dropped silently.

## It refuses to forget where a record comes from

Records are made by the gate only (a provider returns what the target served, `Model\Fetched`,
never a record). Each carries its provenance: the address asked and the one that answered, when,
through which provider, for which operation, on which authority - the declaration's pattern,
reference, date and author, or the default -, robots.txt's state and address, the User-Agent the
target saw. A record is not open data because it was fetched: `mayStore()` and `mayRepublish()`
are true only where a declaration said so and the page did not forbid it.

## It refuses to outrun anyone

A host's limits (its declaration's, or the default's, plus its Crawl-delay) and the provider's own
limits (its policy, as a site tightened it) both apply: the stricter wins. A refused call reaches
nothing. A target that answers 429 holds its host for its Retry-After. Redirects followed by the
direct provider are admitted one by one before they are requested; a service that follows them on
its side cannot ask, so the gate refuses its answer when it ends on a refused address.

## Signals: what is specified, what is only noted

| Signal | Status (read 2026-11-10) | What the family does |
|---|---|---|
| robots.txt | RFC 9309 (2022) | applied in full |
| `Crawl-delay` | no standard | honoured as one request every N seconds |
| `X-Robots-Tag`, meta robots | vendor specifications (Google, Bing), widely followed | read; `noarchive`, `nosnippet` acted on; all kept |
| TDMRep | W3C Community Group Final Report, 2024-05-10 (not a W3C Recommendation) | applied: file, header, meta |
| AI preferences (`Content-Usage`) | IETF AIPREF drafts: draft-ietf-aipref-vocab-08 (2026-09-14), draft-ietf-aipref-attach-05 (2026-08-19); no RFC, the vocabulary not at consensus | the header and robots.txt lines are kept on the record as written (`Signals::$contentUsage`), not enforced; to apply once an RFC fixes them |
| `noai`, `noimageai` | a single vendor's convention, no specification | kept among the directives, not acted on |
| EPUB and PDF TDMRep metadata | specified by TDMRep | not read: the family fetches HTML pages |

The EU's DSM Directive (2019/790, art. 4(3)) makes the text and data mining exception depend on
rights not being reserved "in an appropriate manner, such as machine-readable means"; TDMRep and
the AIPREF drafts are the candidate machine-readable forms. Reading them is necessary, not
sufficient: the family's operator still answers for what it collects.
