# Changelog

## 1.x (unreleased)

- First release of the family: the gate (robots.txt per RFC 9309, target declarations as data
  with a conservative default and the family's shipped refusals, TDMRep, X-Robots-Tag and meta
  robots, per-host limits through omnisense/contracts' PolicyGuard, provenance on every record),
  the `page-fetch`, `extract` and `crawl-runs` contracts, the built-in `direct` fetcher, the
  testing kit (RecordedHttpClient, FixedFetcher, BareTestCase) and the harness.
