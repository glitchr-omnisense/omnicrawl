# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable version. It requires `omnisense/contracts` `^1.0` with no stability flag, so `composer require omnisense/omnicrawl` installs it in a project with Composer's default minimum-stability; the 1.x series follows semantic versioning. A tag `vX.Y.Z` is released by `.github/workflows/release.yml` once the package's CI passes on it.

- Installation: the README and docs/index.md give the install lines and a first call that needs no
  account and no network (the direct provider over the Testing kit's recorded client), and say what
  needs an account (Firecrawl, ScrapingBee, Zyte, Apify: each its API key).

- Plans moved into omnisense/contracts (the usage-policy schema's `plans` member,
  `UsagePolicy::forPlan()`, the factory's `plan` option read by the kernel): `Policy\Plans` is a
  deprecated wrapper over the kernel's, with omnicrawl's InvalidConfigException; its `fromFile()`
  reads a policy.json with plans, or a separate plans file. `resources/plans.schema.json` is
  deprecated. Behaviour unchanged.
- First release of the family: the gate (robots.txt per RFC 9309, target declarations as data
  with a conservative default (each application declares its own refusals), TDMRep, X-Robots-Tag and meta
  robots, per-host limits through omnisense/contracts' PolicyGuard, provenance on every record),
  the `page-fetch`, `extract` and `crawl-runs` contracts, the built-in `direct` fetcher, the
  testing kit (RecordedHttpClient, FixedFetcher, BareTestCase) and the harness.
