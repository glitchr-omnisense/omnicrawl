<?php

namespace Omnicrawl\Tests\Guardrails;

use Omnicrawl\Exception\RefusedException;
use Omnicrawl\Exception\TargetRefusedException;
use Omnicrawl\Exception\UsageLimitReachedException;
use Omnicrawl\Model\Authority;
use Omnicrawl\Target\Targets;
use Omnicrawl\Tests\Kit;
use Omnisense\Contracts\Policy\Permission;
use PHPUnit\Framework\TestCase;

/**
 * A target nobody declared: single pages for previews under robots.txt,
 * never rendered, one request every 10 seconds and 100 a day per host,
 * cached a day at most, nothing said of storing or republishing; no crawl
 * run.
 */
final class DefaultTargetTest extends TestCase
{
    private const ROBOTS = ['~/robots\.txt$~' => [404, '']];

    public function testASinglePageIsFetchedUnderRobotsOnly(): void
    {
        $kit = new Kit(self::ROBOTS, ['https://news.example.com/1' => [200, Kit::page('One')]]);

        $record = $kit->registry->pageFetch('site')->fetch('https://news.example.com/1');
        $kit->now += 10;
        $preview = $kit->registry->extract('site')->extract('https://news.example.com/1');

        self::assertSame(200, $record->status);
        self::assertSame(Authority::RobotsOnly, $record->provenance->authority);
        self::assertNull($record->provenance->target, 'no declaration: the default');
        self::assertSame('One', $preview->preview()['title']);
    }

    public function testACrawlRunIsRefusedAndTheProviderIsNotCalled(): void
    {
        $kit = new Kit(self::ROBOTS);
        $kit->registry->get('site');

        try {
            $kit->registry->crawlRuns('site')->start(['https://news.example.com/1', 'https://news.example.com/2']);
            self::fail('A run over an undeclared site started.');
        } catch (TargetRefusedException $e) {
            self::assertSame(RefusedException::REASON_OPERATION, $e->reason);
            self::assertStringContainsString('declare the target', $e->getMessage());
        }
        self::assertSame([], $kit->calls());
    }

    public function testRenderingIsRefused(): void
    {
        $kit = new Kit(self::ROBOTS);
        $kit->registry->get('site');

        $this->expectException(TargetRefusedException::class);
        try {
            $kit->registry->pageFetch('site')->fetch('https://news.example.com/1', render: true);
        } finally {
            self::assertSame([], $kit->calls());
        }
    }

    public function testOneRequestEveryTenSecondsAndAHundredADayPerHost(): void
    {
        $pages = [];
        for ($i = 0; $i < 101; ++$i) {
            $pages['https://news.example.com/'.$i] = [200, Kit::page()];
        }
        $pages['https://other.example.com/'] = [200, Kit::page()];
        $kit = new Kit(self::ROBOTS, $pages);
        $fetch = $kit->registry->pageFetch('site');

        $fetch->fetch('https://news.example.com/0');
        try {
            $fetch->fetch('https://news.example.com/1');
            self::fail('Two requests in a second to an undeclared host.');
        } catch (UsageLimitReachedException $e) {
            self::assertSame('host:news.example.com', $e->provider);
            self::assertSame(10, $e->retryAfter);
        }
        self::assertSame(200, $fetch->fetch('https://other.example.com/')->status, 'another host counts apart');
        for ($i = 1; $i < 100; ++$i) {
            $kit->now += 10;
            $fetch->fetch('https://news.example.com/'.$i);
        }
        $kit->now += 10;
        try {
            $fetch->fetch('https://news.example.com/100');
            self::fail('A hundred and first request in a day.');
        } catch (UsageLimitReachedException $e) {
            self::assertSame('100/day/application/*', $e->limit?->id());
        }
        self::assertCount(101, $kit->calls(), 'the refused ones reached nothing');
    }

    public function testItsRightsSayNothingIsAllowedButAPreviewsCache(): void
    {
        $kit = new Kit(self::ROBOTS, ['https://news.example.com/1' => [200, Kit::page()]]);
        $record = $kit->registry->pageFetch('site')->fetch('https://news.example.com/1');

        self::assertFalse($record->mayStore(), 'unknown is not permission');
        self::assertFalse($record->mayRepublish());
        self::assertSame(Permission::Unknown, $record->rights->storage);
        self::assertSame(86400, $record->rights->caching->ttl(7 * 86400));
        self::assertCount(2, Targets::default()->rateLimits ?? [], 'the default names its limits');
    }

    public function testAnApplicationMayRefuseEveryUndeclaredTarget(): void
    {
        $targets = new Targets([['target' => 'mine.example.org', 'collection' => 'allowed', 'authority' => 'own-site', 'reference' => 'https://mine.example.org', 'date' => '2026-11-10']], refuseUndeclared: true);

        self::assertFalse($targets->for('news.example.com', '/')->allowed);
        self::assertTrue($targets->for('mine.example.org', '/')->allowed);
    }
}
