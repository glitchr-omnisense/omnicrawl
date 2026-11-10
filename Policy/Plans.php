<?php

namespace Omnicrawl\Policy;

use Omnicrawl\Exception\InvalidConfigException;
use Omnisense\Contracts\Policy\InvalidPolicyException;
use Omnisense\Contracts\Policy\Plans as KernelPlans;
use Omnisense\Contracts\Policy\UsagePolicy;

/**
 * The plans a provider publishes limits for: omnisense/contracts' Plans
 * (the "plans" member of the usage-policy schema, UsagePolicy::forPlan()),
 * under omnicrawl's InvalidConfigException. A provider declares its plans
 * in its policy.json now:
 *
 *   "plans": {"default": "free", "plans": {"free": {...}, "hobby": {...}}}
 *
 * fromFile() reads that policy.json, or a separate plans file of the same
 * shape (the earlier plans.json, resources/plans.schema.json).
 *
 * @deprecated since 1.x, use Omnisense\Contracts\Policy\Plans and UsagePolicy::forPlan()
 */
final readonly class Plans
{
    private function __construct(public KernelPlans $plans)
    {
    }

    public static function of(KernelPlans $plans): self
    {
        return new self($plans);
    }

    /** A policy.json with a "plans" member, or a separate plans file. */
    public static function fromFile(string $path): self
    {
        $json = is_file($path) ? file_get_contents($path) : false;
        $data = false === $json ? null : json_decode($json, true);
        if (!\is_array($data)) {
            throw new InvalidConfigException(\sprintf('No plans in "%s".', $path));
        }
        if (isset($data['provider'])) {
            $plans = self::kernel(static fn () => UsagePolicy::fromArray(self::map($data))->plans);

            return null === $plans ? throw new InvalidConfigException(\sprintf('No plans in "%s".', $path)) : new self($plans);
        }

        return self::fromArray($data);
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(self::kernel(static fn () => KernelPlans::fromArray(self::map($data))));
    }

    /** @return list<string> */
    public function names(): array
    {
        return $this->plans->names();
    }

    /** The plan's name, checked; the default for null. */
    public function check(?string $plan): string
    {
        return self::kernel(fn () => $this->plans->check($plan));
    }

    /** The policy under a plan (the default for null): its limits and concurrency, its source and date. */
    public function apply(UsagePolicy $policy, ?string $plan = null): UsagePolicy
    {
        return self::kernel(fn () => $this->plans->apply($policy, $plan));
    }

    /** @return array{default: string, plans: array<string, array{rateLimits: ?list<array<string, mixed>>, concurrency: ?int, source: string, verifiedAt: string, note: ?string}>} */
    public function toArray(): array
    {
        return $this->plans->toArray();
    }

    /**
     * @template T
     *
     * @param \Closure(): T $read
     *
     * @return T
     */
    private static function kernel(\Closure $read): mixed
    {
        try {
            return $read();
        } catch (InvalidPolicyException $e) {
            throw new InvalidConfigException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function map(array $data): array
    {
        $map = [];
        foreach ($data as $key => $value) {
            $map[(string) $key] = $value;
        }

        return $map;
    }
}
