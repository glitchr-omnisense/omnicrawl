# Changelog

## 1.x (unreleased)

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
