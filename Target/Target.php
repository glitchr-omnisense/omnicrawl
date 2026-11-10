<?php

namespace Omnicrawl\Target;

use Omnicrawl\Exception\InvalidConfigException;
use Omnicrawl\Model\Authority;
use Omnicrawl\Model\Operation;
use Omnisense\Contracts\Policy\Caching;
use Omnisense\Contracts\Policy\DeclaredBy;
use Omnisense\Contracts\Policy\InvalidPolicyException;
use Omnisense\Contracts\Policy\Permission;
use Omnisense\Contracts\Policy\Provenance;
use Omnisense\Contracts\Policy\RateLimit;
use Omnisense\Contracts\Policy\Rights;
use Omnisense\Contracts\Policy\UsagePolicy;
use Omnisense\Contracts\Policy\Window;

/**
 * A target site's declaration, as data (resources/target.schema.json):
 *
 *   {
 *     "target": "docs.example.org",            a host, "*.example.org", or a host and a path prefix ("example.org/news/")
 *     "collection": "allowed",                  or "refused"
 *     "authority": "written-permission",        own-site, written-permission, published-terms, robots-only
 *     "reference": "mail of 2026-10-02 from the editor (archived as DOC-1432)",
 *     "date": "2026-10-02",                     when it was given or read (YYYY-MM-DD)
 *     "operations": ["page-fetch", "extract", "crawl-runs"],
 *     "render": false,                          JavaScript rendering allowed
 *     "rateLimits": [{"max": 1, "window": "second", "every": 2}],
 *     "robots": {"honour": true},               or {"honour": false, "reason": "..."}: a licence only
 *     "rights": {"storage": "allowed", "redistribution": "forbidden", "caching": {"permission": "allowed", "maxTtl": 604800}},
 *     "reason": "...",                          why it is refused, for a refusal
 *     "notes": "..."
 *   }
 *
 * The rules hold at load time, so that a declaration that would say less
 * than it claims cannot be written: every authority but robots-only needs
 * a reference and a date, and so does a refusal and any declaration that
 * allows crawl runs; robots.txt may be set aside only under a licence
 * (own-site, written-permission) and with a written reason; "no limit"
 * ("rateLimits": []) only for one's own site. A declaration that names no
 * limits gets the default's (Targets::DEFAULT_LIMITS); one that names no
 * operations, page-fetch and extract - crawl runs are always named.
 */
final readonly class Target
{
    /** @var list<Operation> */
    public array $operations;

    /** @var ?list<RateLimit> null: the default's limits */
    public ?array $rateLimits;

    /**
     * @param list<Operation>  $operations
     * @param ?list<RateLimit> $rateLimits
     */
    public function __construct(
        public string $pattern,
        public bool $allowed,
        public Authority $authority,
        public ?string $reference = null,
        public ?string $date = null,
        array $operations = [Operation::PageFetch, Operation::Extract],
        public bool $render = false,
        ?array $rateLimits = null,
        public bool $honourRobots = true,
        public ?string $robotsReason = null,
        public Rights $rights = new Rights(),
        public ?string $reason = null,
        public ?string $notes = null,
        public DeclaredBy $declaredBy = DeclaredBy::Site,
    ) {
        $this->operations = array_values(array_unique($operations, \SORT_REGULAR));
        $this->rateLimits = $rateLimits;
        $where = \sprintf('The target "%s"', $pattern);
        Pattern::check($pattern);
        $documented = null !== $reference && '' !== trim($reference) && null !== $date;
        if (null !== $date && (1 !== preg_match('~^\d{4}-\d{2}-\d{2}$~', $date) || false === ($day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date)) || $day->format('Y-m-d') !== $date)) {
            throw new InvalidConfigException($where.'\'s date must be YYYY-MM-DD, not "'.$date.'".');
        }
        if (!$documented && (Authority::RobotsOnly !== $authority || !$allowed || \in_array(Operation::CrawlRuns, $this->operations, true))) {
            throw new InvalidConfigException($where.' needs a reference (an address, a document) and a date: '.($allowed ? 'its authority, '.$authority->value.($authority === Authority::RobotsOnly ? ' with crawl runs' : '').', must be traceable.' : 'a refusal says where it was read.'));
        }
        if (!$honourRobots && !$authority->isLicence()) {
            throw new InvalidConfigException($where.' may set robots.txt aside only as one\'s own site or with written permission, not under '.$authority->value.'.');
        }
        if (!$honourRobots && (null === $robotsReason || '' === trim($robotsReason))) {
            throw new InvalidConfigException($where.' sets robots.txt aside without a reason: write it ("robots": {"honour": false, "reason": "..."}).');
        }
        if ([] === $rateLimits && Authority::OwnSite !== $authority) {
            throw new InvalidConfigException($where.' may go without rate limits only as one\'s own site.');
        }
        if (!$allowed && (null === $reason || '' === trim($reason))) {
            throw new InvalidConfigException($where.' is refused without a reason: write why ("reason").');
        }
    }

    /** @param array<string, mixed> $data the schema's shape */
    public static function fromArray(array $data, DeclaredBy $declaredBy = DeclaredBy::Site): self
    {
        $unknown = array_diff(array_keys($data), ['$schema', 'target', 'collection', 'authority', 'reference', 'date', 'operations', 'render', 'rateLimits', 'robots', 'rights', 'reason', 'notes']);
        $pattern = \is_string($data['target'] ?? null) ? $data['target'] : throw new InvalidConfigException('A target declaration needs "target": a host, "*.host" or "host/path/".');
        if ([] !== $unknown) {
            throw new InvalidConfigException(\sprintf('The target "%s" has keys the schema does not know: %s.', $pattern, implode(', ', $unknown)));
        }
        $collection = $data['collection'] ?? null;
        if ('allowed' !== $collection && 'refused' !== $collection) {
            throw new InvalidConfigException(\sprintf('The target "%s" needs "collection": "allowed" or "refused".', $pattern));
        }
        $authority = Authority::tryFrom(\is_string($data['authority'] ?? null) ? $data['authority'] : '') ?? throw new InvalidConfigException(\sprintf('The target "%s" needs "authority": own-site, written-permission, published-terms or robots-only.', $pattern));
        $operations = [Operation::PageFetch, Operation::Extract];
        if (isset($data['operations'])) {
            $operations = [];
            foreach (\is_array($data['operations']) ? $data['operations'] : [$data['operations']] as $operation) {
                $operations[] = Operation::tryFrom(\is_string($operation) ? $operation : '') ?? throw new InvalidConfigException(\sprintf('The target "%s" names an operation the family does not know: %s (page-fetch, extract, crawl-runs).', $pattern, json_encode($operation)));
            }
        }
        $limits = null;
        if (null !== ($data['rateLimits'] ?? null) && (!\is_array($data['rateLimits']) || !array_is_list($data['rateLimits']))) {
            throw new InvalidConfigException(\sprintf('The target "%s"\'s rateLimits must be a list.', $pattern));
        }
        try {
            if (\is_array($data['rateLimits'] ?? null)) {
                $limits = [];
                foreach ($data['rateLimits'] as $i => $limit) {
                    $limits[] = RateLimit::fromArray(self::map($limit), 'rateLimits['.$i.']');
                }
            }
            $rights = Rights::fromArray(self::map($data['rights'] ?? []));
        } catch (InvalidPolicyException $e) {
            throw new InvalidConfigException(\sprintf('The target "%s": %s', $pattern, $e->getMessage()), 0, $e);
        }
        $robots = self::map($data['robots'] ?? []);

        return new self(
            $pattern,
            'allowed' === $collection,
            $authority,
            self::string($data['reference'] ?? null),
            self::string($data['date'] ?? null),
            $operations,
            true === ($data['render'] ?? false),
            $limits,
            false !== ($robots['honour'] ?? true),
            self::string($robots['reason'] ?? null),
            $rights,
            self::string($data['reason'] ?? null),
            self::string($data['notes'] ?? null),
            $declaredBy,
        );
    }

    /** Whether it allows an operation. */
    public function allows(Operation $operation): bool
    {
        return $this->allowed && \in_array($operation, $this->operations, true);
    }

    /**
     * The usage policy its limits are enforced with on one host: named
     * "host:<host>", so that each host counts apart even under a wildcard;
     * the declared limits (or the default's), and one request every
     * Crawl-delay seconds when robots.txt asks for one.
     */
    public function usagePolicy(string $host, ?float $crawlDelay = null): UsagePolicy
    {
        $limits = $this->rateLimits ?? Targets::defaultLimits();
        if (null !== $crawlDelay && $crawlDelay > 0) {
            $limits[] = new RateLimit(1, Window::Second, every: max(1, (int) ceil($crawlDelay)), note: 'robots.txt Crawl-delay');
        }

        return new UsagePolicy('host:'.$host, $limits, $this->rights, provenance: new Provenance($this->reference, null, $this->date, $this->declaredBy, null, $this->notes));
    }

    /** @return array<string, mixed> the schema's shape */
    public function toArray(): array
    {
        return array_filter([
            'target' => $this->pattern,
            'collection' => $this->allowed ? 'allowed' : 'refused',
            'authority' => $this->authority->value,
            'reference' => $this->reference,
            'date' => $this->date,
            'operations' => array_map(static fn (Operation $o) => $o->value, $this->operations),
            'render' => $this->render,
            'rateLimits' => null === $this->rateLimits ? null : array_map(static fn (RateLimit $l) => $l->toArray(), $this->rateLimits),
            'robots' => $this->honourRobots ? ['honour' => true] : ['honour' => false, 'reason' => $this->robotsReason],
            'rights' => $this->rights->toArray(),
            'reason' => $this->reason,
            'notes' => $this->notes,
        ], static fn ($value) => null !== $value);
    }

    /** The rights of a record of this target whose page asks for no archive: no copy kept, none cached. */
    public function rightsWithoutArchive(): Rights
    {
        $r = $this->rights;

        return new Rights($r->commercial, $r->openSourceRequired, Caching::forbidden(), Permission::Forbidden, Permission::Forbidden, $r->attribution, $r->licence);
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }
        $map = [];
        foreach ($value as $key => $one) {
            $map[(string) $key] = $one;
        }

        return $map;
    }

    private static function string(mixed $value): ?string
    {
        return \is_string($value) && '' !== trim($value) ? $value : null;
    }
}
