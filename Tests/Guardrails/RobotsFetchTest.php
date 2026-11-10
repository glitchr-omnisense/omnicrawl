<?php

namespace Omnicrawl\Tests\Guardrails;

use Omnicrawl\Agent;
use Omnicrawl\Exception\RefusedException;
use Omnicrawl\Exception\RobotsDisallowedException;
use Omnicrawl\Exception\UsageLimitReachedException;
use Omnicrawl\Guard\Gate;
use Omnicrawl\Model\Operation;
use Omnicrawl\Robots\RobotsState;
use Omnicrawl\Testing\RecordedHttpClient;
use Omnicrawl\Tests\Kit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;

/** robots.txt read by the gate itself, with the family's User-Agent, its statuses read as RFC 9309 §2.3.1 says, kept no longer than §2.4 allows. */
final class RobotsFetchTest extends TestCase
{
    public function testTheGateReadsRobotsTxtItselfWithTheFamilysUserAgent(): void
    {
        $kit = new Kit(['~/robots\.txt$~' => [200, "User-agent: *\nDisallow: /private/\n", ['content-type' => ['text/plain']]]], ['https://example.org/' => [200, Kit::page()]]);

        $record = $kit->registry->pageFetch('site')->fetch('https://example.org/');

        self::assertSame(RobotsState::Parsed, $record->provenance->robots);
        self::assertSame('https://example.org/robots.txt', $record->provenance->robotsUrl);
        self::assertSame('GET https://example.org/robots.txt', $kit->gateRequests()[0]);
        self::assertSame(Kit::UA.' omnicrawl/1', $kit->http->header('User-Agent', 0));
        self::assertSame(['fetch https://example.org/'], $kit->calls());
    }

    public function testADisallowedAddressNeverReachesTheProvider(): void
    {
        $kit = new Kit(['~/robots\.txt$~' => [200, "User-agent: ExampleBot\nDisallow: /private/\n"]]);
        $kit->registry->get('site');

        try {
            $kit->registry->pageFetch('site')->fetch('https://example.org/private/report');
            self::fail('A disallowed address was fetched.');
        } catch (RobotsDisallowedException $e) {
            self::assertSame(RefusedException::REASON_ROBOTS, $e->reason);
            self::assertSame('https://example.org/robots.txt', $e->reference);
            self::assertStringContainsString('disallow: /private/', $e->getMessage());
        }
        self::assertSame([], $kit->calls(), 'the provider was not called');
    }

    /** @return iterable<string, array{int, bool}> */
    public static function statuses(): iterable
    {
        yield '200 with no rule' => [200, true];
        yield '404: unavailable, allowed' => [404, true];
        yield '401: unavailable, allowed' => [401, true];
        yield '403: unavailable, allowed' => [403, true];
        yield '410: unavailable, allowed' => [410, true];
        yield '429: the server refuses, disallowed' => [429, false];
        yield '500: unreachable, disallowed' => [500, false];
        yield '503: unreachable, disallowed' => [503, false];
        yield '302 left after five redirects: disallowed' => [302, false];
    }

    #[DataProvider('statuses')]
    public function testEachStatusAsTheRfcReadsIt(int $status, bool $allowed): void
    {
        $kit = new Kit(['~/robots\.txt$~' => [$status, '']], ['https://example.org/page' => [200, Kit::page()]]);
        $kit->registry->get('site');

        try {
            $kit->registry->pageFetch('site')->fetch('https://example.org/page');
            self::assertTrue($allowed, 'fetched though robots.txt answered '.$status);
        } catch (RobotsDisallowedException $e) {
            self::assertFalse($allowed, 'refused though robots.txt answered '.$status);
            self::assertSame(RefusedException::REASON_ROBOTS_UNREACHABLE, $e->reason);
            self::assertSame([], $kit->calls());
        }
    }

    public function testAnUnreachableHostIsCompleteDisallow(): void
    {
        $kit = new Kit();
        $unreachable = new Gate(Agent::parse(Kit::UA), new RecordedHttpClient(static fn () => throw new TransportException('Could not resolve host')));

        $refusal = $unreachable->refusal('https://example.org/', Operation::PageFetch, $kit->registry->get('site'));

        self::assertInstanceOf(RobotsDisallowedException::class, $refusal);
        self::assertSame(RefusedException::REASON_ROBOTS_UNREACHABLE, $refusal->reason);
    }

    public function testRobotsTxtIsKeptADayAtMostAndPerOrigin(): void
    {
        $kit = new Kit(['~/robots\.txt$~' => [200, "User-agent: *\nAllow: /\n"]], ['https://example.org/a' => [200, Kit::page()], 'https://example.org/b' => [200, Kit::page()], 'http://example.org/c' => [200, Kit::page()]]);
        $fetch = $kit->registry->pageFetch('site');

        $fetch->fetch('https://example.org/a');
        $kit->now += 11;
        $fetch->fetch('https://example.org/b');
        self::assertCount(2, $kit->gateRequests(), 'robots.txt and tdmrep.json read once for two pages');
        $kit->now += 11;
        $fetch->fetch('http://example.org/c');
        self::assertContains('GET http://example.org/robots.txt', $kit->gateRequests(), 'another scheme is another origin (§2.3)');
        $kit->now += 86400;
        $fetch->fetch('https://example.org/a');
        self::assertCount(2, array_keys($kit->gateRequests(), 'GET https://example.org/robots.txt', true), 'read again after 24 hours (§2.4)');
    }

    public function testAShorterMaxAgeIsHonoured(): void
    {
        $kit = new Kit(['~/robots\.txt$~' => [200, "User-agent: *\nAllow: /\n", ['cache-control' => ['max-age=120']]]], ['https://example.org/a' => [200, Kit::page()]]);
        $fetch = $kit->registry->pageFetch('site');
        $fetch->fetch('https://example.org/a');
        $kit->now += 121;
        $fetch->fetch('https://example.org/a');

        self::assertCount(2, array_keys($kit->gateRequests(), 'GET https://example.org/robots.txt', true));
    }

    public function testCrawlDelayBecomesTheHostsPace(): void
    {
        $kit = new Kit(['~/robots\.txt$~' => [200, "User-agent: ExampleBot\nCrawl-delay: 30\nAllow: /\n"]], ['https://example.org/a' => [200, Kit::page()]]);
        $fetch = $kit->registry->pageFetch('site');
        $fetch->fetch('https://example.org/a');
        $kit->now += 11;

        try {
            $fetch->fetch('https://example.org/a');
            self::fail('Crawl-delay: 30 was not honoured.');
        } catch (UsageLimitReachedException $e) {
            self::assertSame('host:example.org', $e->provider);
            self::assertSame('1/30second/application/*', $e->limit?->id());
        }
        $kit->now += 20;
        self::assertSame(200, $fetch->fetch('https://example.org/a')->status);
    }
}
