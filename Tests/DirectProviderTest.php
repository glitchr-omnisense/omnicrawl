<?php

namespace Omnicrawl\Tests;

use Omnicrawl\Agent;
use Omnicrawl\Direct\DirectFactory;
use Omnicrawl\Exception\NotSupportedException;
use Omnicrawl\Exception\RobotsDisallowedException;
use Omnicrawl\Guard\Gate;
use Omnicrawl\Registry;
use Omnicrawl\Testing\RecordedHttpClient;
use PHPUnit\Framework\TestCase;

/** The direct fetcher: one GET with the family's User-Agent, the target's status, headers and final address as they came. */
final class DirectProviderTest extends TestCase
{
    public function testAPageFetchedDirectly(): void
    {
        $http = RecordedHttpClient::routes([
            '~^GET https://example\.org/robots\.txt$~' => [200, "User-agent: *\nDisallow: /admin/\n", ['content-type' => ['text/plain']]],
            '~^GET https://example\.org/hello$~' => [200, Kit::page('Hello'), ['content-type' => ['text/html; charset=utf-8'], 'X-Robots-Tag' => ['noarchive']]],
        ]);
        $registry = new Registry([new DirectFactory($http)], ['direct' => ['factory' => 'direct']], new Gate(Agent::parse(Kit::UA), $http));

        $record = $registry->pageFetch('direct')->fetch('https://example.org/hello#top');

        self::assertSame('https://example.org/hello', $record->url, 'no fragment');
        self::assertSame(200, $record->status);
        self::assertTrue($record->html);
        self::assertSame('noarchive', $record->header('x-robots-tag'));
        self::assertTrue($record->signals->noarchive());
        self::assertSame('direct', $record->provenance->provider);
        $last = $http->requests[\count($http->requests) - 1];
        self::assertSame(['GET', 'https://example.org/hello'], [$last['method'], $last['url']]);
        self::assertSame(Kit::UA.' omnicrawl/1', $http->header('User-Agent'));
        self::assertSame(0, $last['options']['max_redirects'], 'redirects followed one by one, each admitted first');
        self::assertSame(['page-fetch', 'extract'], $registry->get('direct')->capabilities()->contracts());
        self::assertSame([], $registry->policy('direct')->rateLimits);

        $this->expectException(RobotsDisallowedException::class);
        $registry->pageFetch('direct')->fetch('https://example.org/admin/');
    }

    public function testItDoesNotRender(): void
    {
        $http = RecordedHttpClient::routes([]);
        $registry = new Registry([new DirectFactory($http)], ['direct' => ['factory' => 'direct']], new Gate(Agent::parse(Kit::UA), $http, new \Omnicrawl\Target\Targets([['target' => 'mine.example.org', 'collection' => 'allowed', 'authority' => 'own-site', 'reference' => 'https://mine.example.org', 'date' => '2026-11-10', 'render' => true]])));

        $this->expectException(NotSupportedException::class);
        $registry->pageFetch('direct')->fetch('https://mine.example.org/', render: true);
    }
}
