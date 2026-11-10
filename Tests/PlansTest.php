<?php

namespace Omnicrawl\Tests;

use Omnicrawl\Exception\InvalidConfigException;
use Omnicrawl\Policy\Plans;
use Omnicrawl\Testing\FixedFactory;
use Omnisense\Contracts\Policy\InvalidPolicyException;
use Omnisense\Contracts\Policy\RateLimit;
use Omnisense\Contracts\Policy\UsagePolicy;
use PHPUnit\Framework\TestCase;

/** A provider's plans: the default the most restrictive, one picked by name, an unknown one refused, the picked one only tightened. */
final class PlansTest extends TestCase
{
    private static function base(): UsagePolicy
    {
        return UsagePolicy::fromArray(['provider' => 'example', 'rateLimits' => [['max' => 10, 'window' => 'minute', 'operations' => ['fetch']]], 'concurrency' => 2, 'provenance' => ['terms' => 'https://provider.example.com/terms', 'notes' => 'Terms read.']]);
    }

    public function testThePlanPickedReplacesTheLimitsAndConcurrency(): void
    {
        $plans = Plans::fromFile(__DIR__.'/Fixtures/plans.json');

        self::assertSame(['free', 'pro'], $plans->names());
        $free = $plans->apply(self::base());
        $pro = $plans->apply(self::base(), 'pro');
        self::assertSame(['10/minute/application/fetch'], array_map(static fn (RateLimit $l) => $l->id(), $free->rateLimits ?? []));
        self::assertSame(['500/minute/application/fetch'], array_map(static fn (RateLimit $l) => $l->id(), $pro->rateLimits ?? []));
        self::assertSame(25, $pro->concurrency);
        self::assertSame(['https://provider.example.com/terms', 'https://provider.example.com/limits', '2026-11-10'], [$pro->provenance->terms, $pro->provenance->policy, $pro->provenance->verifiedAt]);
        self::assertStringStartsWith('Plan "pro", read at https://provider.example.com/limits on 2026-11-10. The paid tier. Terms read.', (string) $pro->provenance->notes);
        self::assertEquals(self::base()->rateLimits, $free->rateLimits, 'policy.json\'s own limits are the default plan\'s');
    }

    public function testASiteTightensThePlanItPickedAndCannotLoosenIt(): void
    {
        $pro = Plans::fromFile(__DIR__.'/Fixtures/plans.json')->apply(self::base(), 'pro');

        self::assertCount(2, $pro->tighten(['rateLimits' => [['max' => 100, 'window' => 'minute', 'operations' => ['fetch']]]])->rateLimits ?? []);
        $this->expectException(InvalidPolicyException::class);
        $pro->tighten(['rateLimits' => [['max' => 900, 'window' => 'minute', 'operations' => ['fetch']]]]);
    }

    public function testAnUnknownPlanIsRefused(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('No "enterprise" plan; declared: free, pro.');
        Plans::fromFile(__DIR__.'/Fixtures/plans.json')->apply(self::base(), 'enterprise');
    }

    public function testAPlanWithoutItsSourceOrAnAbsentDefaultIsRefused(): void
    {
        foreach ([
            ['default' => 'free', 'plans' => ['free' => ['concurrency' => 1, 'verifiedAt' => '2026-11-10']]],
            ['default' => 'free', 'plans' => ['free' => ['concurrency' => 1, 'source' => 'https://x.example']]],
            ['default' => 'gold', 'plans' => ['free' => ['concurrency' => 1, 'source' => 'https://x.example', 'verifiedAt' => '2026-11-10']]],
            ['default' => 'free', 'plans' => ['free' => ['concurrency' => 0, 'source' => 'https://x.example', 'verifiedAt' => '2026-11-10']]],
        ] as $plans) {
            try {
                Plans::fromArray($plans);
                self::fail('Plans accepted: '.json_encode($plans));
            } catch (InvalidConfigException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAProviderWithoutPlansRefusesThePlanOption(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('declares no plans');
        (new FixedFactory())->create(['user_agent' => Kit::UA, 'plan' => 'pro']);
    }
}
