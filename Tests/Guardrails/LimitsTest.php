<?php

namespace Omnicrawl\Tests\Guardrails;

use Omnicrawl\Exception\UsageLimitReachedException;
use Omnicrawl\Tests\Kit;
use PHPUnit\Framework\TestCase;

/** The target's limits and the provider's both apply: the stricter wins, and a refused call reaches nothing. */
final class LimitsTest extends TestCase
{
    private const PARTNER = ['target' => '*.partner.example.org', 'collection' => 'allowed', 'authority' => 'written-permission', 'reference' => 'Contract 2026-14', 'date' => '2026-10-01', 'operations' => ['page-fetch', 'extract', 'crawl-runs']];

    /** @return array<string, array{0: int, 1: string}> */
    private static function pages(): array
    {
        $pages = [];
        foreach (['a', 'b'] as $host) {
            for ($i = 0; $i < 12; ++$i) {
                $pages['https://'.$host.'.partner.example.org/'.$i] = [200, Kit::page()];
            }
        }

        return $pages;
    }

    public function testTheProvidersLimitWinsWhenItIsStricter(): void
    {
        $kit = new Kit(['~/robots\.txt$~' => [404, '']], self::pages(), [self::PARTNER + ['rateLimits' => [['max' => 10, 'window' => 'minute']]]], provider: ['policy' => ['rateLimits' => [['max' => 2, 'window' => 'minute', 'operations' => ['fetch']]]]]);
        $fetch = $kit->registry->pageFetch('site');

        $fetch->fetch('https://a.partner.example.org/0');
        $fetch->fetch('https://a.partner.example.org/1');
        try {
            $fetch->fetch('https://a.partner.example.org/2');
            self::fail('The provider\'s limit was passed.');
        } catch (UsageLimitReachedException $e) {
            self::assertSame('fixed', $e->provider);
            self::assertSame('2/minute/application/fetch', $e->limit?->id());
        }
        self::assertCount(2, $kit->calls());
    }

    public function testTheTargetsLimitWinsWhenItIsStricter(): void
    {
        $kit = new Kit(['~/robots\.txt$~' => [404, '']], self::pages(), [self::PARTNER + ['rateLimits' => [['max' => 2, 'window' => 'minute']]]], provider: ['policy' => ['rateLimits' => [['max' => 10, 'window' => 'minute']]]]);
        $fetch = $kit->registry->pageFetch('site');

        $fetch->fetch('https://a.partner.example.org/0');
        $fetch->fetch('https://a.partner.example.org/1');
        try {
            $fetch->fetch('https://a.partner.example.org/2');
            self::fail('The target\'s limit was passed.');
        } catch (UsageLimitReachedException $e) {
            self::assertSame('host:a.partner.example.org', $e->provider);
        }
        self::assertSame(200, $fetch->fetch('https://b.partner.example.org/0')->status, 'each host under a wildcard counts apart');
        self::assertCount(3, $kit->calls());
    }

    public function testARunCountsEachHostsShareBeforeTheServiceIsCalled(): void
    {
        $kit = new Kit(['~/robots\.txt$~' => [404, '']], self::pages(), [self::PARTNER + ['rateLimits' => [['max' => 5, 'window' => 'hour']]]]);
        $kit->registry->get('site');
        $runs = $kit->registry->crawlRuns('site');
        $urls = array_map(static fn (int $i) => 'https://a.partner.example.org/'.$i, range(0, 5));

        try {
            $runs->start($urls);
            self::fail('A run of six pages on a host allowed five an hour.');
        } catch (UsageLimitReachedException $e) {
            self::assertSame('host:a.partner.example.org', $e->provider);
        }
        self::assertSame([], $kit->calls());

        $runs->start(\array_slice($urls, 0, 5));
        self::assertCount(1, $kit->calls());
        $this->expectException(UsageLimitReachedException::class);
        $kit->registry->pageFetch('site')->fetch('https://a.partner.example.org/9');
    }
}
