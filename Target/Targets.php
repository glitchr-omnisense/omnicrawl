<?php

namespace Omnicrawl\Target;

use Omnicrawl\Exception\InvalidConfigException;
use Omnicrawl\Model\Authority;
use Omnicrawl\Model\Operation;
use Omnisense\Contracts\Policy\Caching;
use Omnisense\Contracts\Policy\DeclaredBy;
use Omnisense\Contracts\Policy\Permission;
use Omnisense\Contracts\Policy\RateLimit;
use Omnisense\Contracts\Policy\Rights;
use Omnisense\Contracts\Policy\Window;

/**
 * Every target the application knows, and what applies to the others:
 *
 * - the application's declarations (Target, or the schema's arrays, or a
 *   JSON file: fromFile()) - its refusals among them: the family ships no
 *   list of third-party sites, each application declares the sites it
 *   refuses, with the terms' reference and the day it read them;
 * - the default, for a target nobody declared (default()), or a refusal
 *   of every undeclared target ($refuseUndeclared).
 *
 * The most specific pattern that covers an address decides (Pattern).
 *
 * The default, and why: an undeclared site has said nothing to this
 * application but what its robots.txt says, and robots.txt says what a
 * crawler may fetch, not what may be done with it. So the default allows
 * what a link preview needs and nothing that collects: single pages
 * (page-fetch, extract) under robots.txt, never rendered (a preview needs
 * no JavaScript, and rendering loads every asset of the page), one request
 * every 10 seconds and 100 a day per host (a preview is one request; a
 * hundred a day is a busy forum's links, not a harvest), its answers
 * cached a day at most, nothing said of storing or republishing them
 * (unknown, so mayStore() and mayRepublish() say no). Crawl runs are
 * refused: a run over a site is collection, and collection needs a
 * declaration with a reference.
 */
final class Targets
{
    /** The pattern of the default, which covers what no declaration does. */
    public const ANY = '*';

    public const SCHEMA = __DIR__.'/../resources/target.schema.json';

    /** @var list<Target> */
    private array $declared = [];

    private readonly Target $default;

    /** @param iterable<Target|array<string, mixed>> $declared */
    public function __construct(iterable $declared = [], bool $refuseUndeclared = false)
    {
        foreach ($declared as $target) {
            $this->declared[] = $target instanceof Target ? $target : Target::fromArray($target);
        }
        $this->default = $refuseUndeclared ? self::refusal() : self::default();
    }

    /**
     * A site's declarations from a JSON file: a list of targets, or
     * {"targets": [...]}.
     */
    public static function fromFile(string $path, bool $refuseUndeclared = false): self
    {
        return new self(self::read($path, DeclaredBy::Site), $refuseUndeclared);
    }

    /** What applies to a target nobody declared. */
    public static function default(): Target
    {
        return new Target(
            self::ANY,
            true,
            Authority::RobotsOnly,
            operations: [Operation::PageFetch, Operation::Extract],
            render: false,
            rateLimits: self::defaultLimits(),
            rights: new Rights(Permission::Unknown, null, new Caching(Permission::Unknown, 86400), Permission::Unknown, Permission::Unknown),
            notes: 'The family\'s default for an undeclared site: single pages under robots.txt, for link previews; no crawl run, no rendering; nothing said of storing or republishing.',
            declaredBy: DeclaredBy::Maintainer,
        );
    }

    /** @return list<RateLimit> one request every 10 seconds, 100 a day, per host */
    public static function defaultLimits(): array
    {
        return [
            new RateLimit(1, Window::Second, every: 10, note: 'the family\'s default: one request every 10 seconds per host'),
            new RateLimit(100, Window::Day, note: 'the family\'s default: 100 requests a day per host'),
        ];
    }

    /** The target that decides for an address's host and path ("/a/b?c"): a declaration, or the default. */
    public function for(string $host, string $path): Target
    {
        return self::best($this->declared, $host, $path) ?? $this->default;
    }

    /** @return list<Target> */
    public function declared(): array
    {
        return $this->declared;
    }

    /** @param list<Target> $targets */
    private static function best(array $targets, string $host, string $path): ?Target
    {
        $best = null;
        foreach ($targets as $target) {
            if (Pattern::matches($target->pattern, $host, $path) && (null === $best || Pattern::specificity($target->pattern) > Pattern::specificity($best->pattern))) {
                $best = $target;
            }
        }

        return $best;
    }

    private static function refusal(): Target
    {
        return new Target(self::ANY, false, Authority::RobotsOnly, 'omnicrawl: undeclared targets refused by the application\'s configuration', '2026-11-10', [], reason: 'The application refuses every target it has not declared.', declaredBy: DeclaredBy::Site);
    }

    /** @return list<Target> */
    private static function read(string $path, DeclaredBy $by): array
    {
        $json = is_file($path) ? file_get_contents($path) : false;
        $data = false === $json ? null : json_decode($json, true);
        if (\is_array($data) && isset($data['targets'])) {
            $data = $data['targets'];
        }
        if (!\is_array($data) || !array_is_list($data)) {
            throw new InvalidConfigException(\sprintf('No list of targets in "%s".', $path));
        }
        $targets = [];
        foreach ($data as $i => $one) {
            if (!\is_array($one)) {
                throw new InvalidConfigException(\sprintf('The target #%d of "%s" is not an object.', $i, $path));
            }
            $map = [];
            foreach ($one as $key => $value) {
                $map[(string) $key] = $value;
            }
            $targets[] = Target::fromArray($map, $by);
        }

        return $targets;
    }
}
