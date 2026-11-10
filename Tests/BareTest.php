<?php

namespace Omnicrawl\Tests;

use Omnicrawl\Http\Data;
use Omnicrawl\Registry;
use Omnicrawl\Testing\BareTestCase;

/**
 * omnisense/omnicrawl with no framework: the harness's bare script makes a
 * link preview through the gate and the direct provider, is refused by
 * robots.txt and by the shipped list, in a PHP process of its own - nothing
 * may load from outside the package but omnisense/contracts and Symfony's
 * HTTP client contracts (interfaces only).
 */
final class BareTest extends BareTestCase
{
    protected static function entryClass(): string
    {
        return Registry::class;
    }

    protected static function requiredPackages(): array
    {
        return ['symfony/http-client-contracts'];
    }

    public function testAPreviewAndTwoRefusalsLoadNothingButOmnicrawlAndWhatItRequires(): void
    {
        $report = Data::of(self::php([__DIR__.'/../docker/harness/bin/bare']));

        self::assertSame(['page-fetch', 'extract'], $report->get('capabilities'));
        self::assertSame(['An article', 'What it says', 'https://news.example.com/cover.jpg', 'robots-only', 'parsed'], $report->get('preview'));
        self::assertSame([['robots-disallowed', 'https://news.example.com/robots.txt'], ['declared-refused', 'https://transfer.navitime.biz/tokyu/pc/disclaimer/DisclaimerInformation']], $report->get('refused'));
        self::assertSame('not supported by the direct provider', $report->get('runs'));
        self::assertSame([3, 0], $report->get('requests'), 'robots.txt, tdmrep.json, the page; nothing for the refused ones');
        self::assertSame('BareBot/1.0 (+https://example.org/bot; bot@example.org) omnicrawl/1', $report->get('user-agent'));

        $symbols = $report->strings('loaded.symbols');
        self::assertContains('Omnicrawl\Guard\Gate', $symbols);
        self::assertSame([], self::foreign($symbols), 'no class of a framework, an HTTP client or an application');
        self::assertSame([], self::outside($report->strings('loaded.files'), $report->strings('loaded.eager')), 'no file but the package and what it requires');
    }
}
