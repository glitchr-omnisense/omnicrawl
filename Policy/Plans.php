<?php

namespace Omnicrawl\Policy;

use Omnicrawl\Exception\InvalidConfigException;
use Omnisense\Contracts\Policy\InvalidPolicyException;
use Omnisense\Contracts\Policy\Provenance;
use Omnisense\Contracts\Policy\RateLimit;
use Omnisense\Contracts\Policy\UsagePolicy;

/**
 * The plans a provider publishes limits for, declared as data beside its
 * policy (plans.json, resources/plans.schema.json):
 *
 *   {
 *     "default": "free",
 *     "plans": {
 *       "free":  {"rateLimits": [{"max": 10, "window": "minute", "operations": ["fetch"]}], "concurrency": 2, "source": "https://...", "verifiedAt": "2026-11-10"},
 *       "hobby": {"rateLimits": [{"max": 100, "window": "minute", "operations": ["fetch"]}], "concurrency": 5, "source": "https://...", "verifiedAt": "2026-11-10"}
 *     }
 *   }
 *
 * The default is the most restrictive plan, and the policy's own
 * rateLimits and concurrency are the default plan's, so that policy.json
 * reads the same where plans are not known. A site picks its plan with the
 * factory's "plan" option (an unknown one is refused), then may only
 * tighten it ("policy" option, UsagePolicy::tighten()).
 *
 * The merge (apply()): the plan's rateLimits, when it names them, replace
 * the policy's; its concurrency, when it names one, replaces the policy's;
 * the provenance's policy and verifiedAt become the plan's source and
 * date, and its notes say which plan applies. Rights, requirements and
 * terms are the provider's, whatever the plan.
 *
 * Written so that it can move into omnisense/contracts' UsagePolicy as it
 * is: a "plans" member of the same shape in the policy's schema, this merge
 * as UsagePolicy::forPlan().
 */
final readonly class Plans
{
    /** @param array<string, array{rateLimits: ?list<RateLimit>, concurrency: ?int, source: string, verifiedAt: string, note: ?string}> $plans */
    private function __construct(public string $default, private array $plans)
    {
    }

    public static function fromFile(string $path): self
    {
        $json = is_file($path) ? file_get_contents($path) : false;
        $data = false === $json ? null : json_decode($json, true);

        return \is_array($data) ? self::fromArray($data) : throw new InvalidConfigException(\sprintf('No plans in "%s".', $path));
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $unknown = array_diff(array_keys($data), ['$schema', 'default', 'plans']);
        if ([] !== $unknown) {
            throw new InvalidConfigException('Plans have keys the schema does not know: '.implode(', ', $unknown).'.');
        }
        $default = \is_string($data['default'] ?? null) ? $data['default'] : throw new InvalidConfigException('Plans need a "default" plan.');
        $given = \is_array($data['plans'] ?? null) ? $data['plans'] : [];
        $plans = [];
        foreach ($given as $name => $plan) {
            $name = (string) $name;
            if (!\is_array($plan) || [] !== array_diff(array_keys($plan), ['rateLimits', 'concurrency', 'source', 'verifiedAt', 'note'])) {
                throw new InvalidConfigException(\sprintf('The plan "%s" is an object of rateLimits, concurrency, source, verifiedAt and note.', $name));
            }
            $source = \is_string($plan['source'] ?? null) && '' !== $plan['source'] ? $plan['source'] : throw new InvalidConfigException(\sprintf('The plan "%s" needs its "source": where its limits are published.', $name));
            $verified = \is_string($plan['verifiedAt'] ?? null) && 1 === preg_match('~^\d{4}-\d{2}-\d{2}$~', $plan['verifiedAt']) ? $plan['verifiedAt'] : throw new InvalidConfigException(\sprintf('The plan "%s" needs "verifiedAt" (YYYY-MM-DD).', $name));
            $limits = null;
            if (isset($plan['rateLimits'])) {
                if (!\is_array($plan['rateLimits']) || !array_is_list($plan['rateLimits'])) {
                    throw new InvalidConfigException(\sprintf('The plan "%s"\'s rateLimits must be a list.', $name));
                }
                $limits = [];
                foreach ($plan['rateLimits'] as $i => $limit) {
                    try {
                        $limits[] = RateLimit::fromArray(\is_array($limit) ? array_combine(array_map('strval', array_keys($limit)), $limit) : [], 'plans.'.$name.'.rateLimits['.$i.']');
                    } catch (InvalidPolicyException $e) {
                        throw new InvalidConfigException($e->getMessage(), 0, $e);
                    }
                }
            }
            $concurrency = $plan['concurrency'] ?? null;
            if (null !== $concurrency && (!\is_int($concurrency) || $concurrency < 1)) {
                throw new InvalidConfigException(\sprintf('The plan "%s"\'s concurrency must be an integer of 1 or more.', $name));
            }
            $plans[$name] = ['rateLimits' => $limits, 'concurrency' => $concurrency, 'source' => $source, 'verifiedAt' => $verified, 'note' => \is_string($plan['note'] ?? null) ? $plan['note'] : null];
        }
        if (!isset($plans[$default])) {
            throw new InvalidConfigException(\sprintf('The default plan "%s" is not among the plans declared.', $default));
        }

        return new self($default, $plans);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->plans);
    }

    /** The plan's name, checked; the default for null. */
    public function check(?string $plan): string
    {
        $plan ??= $this->default;

        return isset($this->plans[$plan]) ? $plan : throw new InvalidConfigException(\sprintf('No "%s" plan; declared: %s.', $plan, implode(', ', $this->names())));
    }

    /** The policy under a plan (the default for null): its limits and concurrency, its source and date. */
    public function apply(UsagePolicy $policy, ?string $plan = null): UsagePolicy
    {
        $name = $this->check($plan);
        $chosen = $this->plans[$name];
        $p = $policy->provenance;
        $notes = trim(\sprintf('Plan "%s"%s, read at %s on %s. %s', $name, $name === $this->default ? ' (the default)' : '', $chosen['source'], $chosen['verifiedAt'], $chosen['note'] ?? '').' '.($p->notes ?? ''));

        return new UsagePolicy(
            $policy->provider,
            $chosen['rateLimits'] ?? $policy->rateLimits,
            $policy->rights,
            $policy->requirements,
            new Provenance($p->terms, $chosen['source'], $chosen['verifiedAt'], $p->declaredBy, $p->contact, $notes),
            $chosen['concurrency'] ?? $policy->concurrency,
        );
    }

    /** @return array{default: string, plans: array<string, array{rateLimits: ?list<array<string, mixed>>, concurrency: ?int, source: string, verifiedAt: string, note: ?string}>} the schema's shape, what an export adds beside the policy */
    public function toArray(): array
    {
        return ['default' => $this->default, 'plans' => array_map(static fn (array $plan) => ['rateLimits' => null === $plan['rateLimits'] ? null : array_map(static fn (RateLimit $l) => $l->toArray(), $plan['rateLimits'])] + $plan, $this->plans)];
    }
}
