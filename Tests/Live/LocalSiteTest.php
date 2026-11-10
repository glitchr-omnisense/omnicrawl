<?php

namespace Omnicrawl\Tests\Live;

use Omnicrawl\Agent;
use Omnicrawl\Direct\DirectFactory;
use Omnicrawl\Exception\RefusedException;
use Omnicrawl\Exception\UsageLimitReachedException;
use Omnicrawl\Guard\Gate;
use Omnicrawl\Model\ExtractRequest;
use Omnicrawl\Registry;
use Omnicrawl\Target\Targets;
use Omnicrawl\Tests\Kit;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * The gate and the direct provider over the network, against the
 * harness's own site (Fixtures/site, served by php -S on 127.0.0.1 inside
 * the container) - never a third-party site: robots.txt read with the
 * family's User-Agent and obeyed, Crawl-delay kept, a TDM reservation, a
 * redirect into a disallowed path, a 503 robots.txt, a 429.
 */
#[Group('live')]
final class LocalSiteTest extends TestCase
{
    /** @var list<array{resource, string, string}> */
    private static array $servers = [];

    private static string $up = '';

    private static string $down = '';

    public static function setUpBeforeClass(): void
    {
        self::$up = self::serve('up');
        self::$down = self::serve('down');
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$servers as [$process, , $log]) {
            proc_terminate($process);
            proc_close($process);
            @unlink($log);
        }
        self::$servers = [];
    }

    public function testTheLocalSiteThroughTheGate(): void
    {
        $registry = self::registry();
        $extract = $registry->extract('direct');
        $fetch = $registry->pageFetch('direct');

        $article = $extract->extract(self::$up.'/article', new ExtractRequest(['h1' => 'h1']));
        self::assertSame('A local article', $article->preview()['title']);
        self::assertSame(self::$up.'/cover.png', $article->preview()['image']);
        self::assertTrue($article->record->signals->noarchive());
        self::assertFalse($article->record->mayStore());

        foreach (['/private/x' => RefusedException::REASON_ROBOTS, '/no-family/x' => RefusedException::REASON_ROBOTS] as $path => $reason) {
            try {
                $fetch->fetch(self::$up.$path);
                self::fail($path.' was fetched.');
            } catch (RefusedException $e) {
                self::assertSame($reason, $e->reason);
            }
        }

        try {
            $fetch->fetch(self::$up.'/page');
            self::fail('Crawl-delay: 1 was not kept.');
        } catch (UsageLimitReachedException $e) {
            self::assertSame(1, $e->retryAfter);
        }
        usleep(1_100_000);
        self::assertSame(200, $fetch->fetch(self::$up.'/page')->status);

        usleep(1_100_000);
        try {
            $extract->extract(self::$up.'/reserved/a');
            self::fail('A reserved path was mined.');
        } catch (RefusedException $e) {
            self::assertSame(RefusedException::REASON_TDM, $e->reason);
        }

        try {
            $fetch->fetch(self::$up.'/redirect');
            self::fail('A redirect into /private/ was kept.');
        } catch (RefusedException $e) {
            self::assertSame(RefusedException::REASON_REDIRECT, $e->reason);
        }

        usleep(1_100_000);
        try {
            $fetch->fetch(self::$up.'/slow-down');
            self::fail('A 429 was kept as a record.');
        } catch (UsageLimitReachedException $e) {
            self::assertSame(120, $e->retryAfter);
        }
        usleep(1_100_000);
        try {
            $fetch->fetch(self::$up.'/page');
            self::fail('The host\'s Retry-After was not kept.');
        } catch (UsageLimitReachedException $e) {
            self::assertGreaterThan(100, $e->retryAfter);
        }

        try {
            $fetch->fetch(self::$down.'/page');
            self::fail('A site whose robots.txt answers 503 was fetched.');
        } catch (RefusedException $e) {
            self::assertSame(RefusedException::REASON_ROBOTS_UNREACHABLE, $e->reason);
        }

        $up = self::log(0);
        $agent = Kit::UA.' omnicrawl/1';
        self::assertContains('/robots.txt '.$agent, $up, 'robots.txt read with the family\'s User-Agent');
        self::assertContains('/article '.$agent, $up);
        self::assertSame([], preg_grep('~^/(private|no-family|reserved)/~', $up), 'no disallowed or reserved path was requested');
        self::assertCount(1, preg_grep('~^/robots\.txt ~', $up) ?: [], 'robots.txt read once');
        self::assertSame(['/robots.txt '.$agent], self::log(1), 'the 503 site was asked for its robots.txt only');
    }

    private static function registry(): Registry
    {
        $http = HttpClient::create();
        $host = static fn (string $base) => (string) preg_replace('~^http://~', '', $base);
        $gate = new Gate(Agent::parse(Kit::UA), $http, new Targets([
            ['target' => $host(self::$up), 'collection' => 'allowed', 'authority' => 'published-terms', 'reference' => 'docker/harness: the local test site', 'date' => '2026-11-10', 'rateLimits' => [['max' => 120, 'window' => 'minute']]],
            ['target' => $host(self::$down), 'collection' => 'allowed', 'authority' => 'written-permission', 'reference' => 'docker/harness: the local test site', 'date' => '2026-11-10', 'rateLimits' => [['max' => 120, 'window' => 'minute']]],
        ]));

        return new Registry([new DirectFactory($http)], ['direct' => ['factory' => 'direct']], $gate);
    }

    private static function serve(string $mode): string
    {
        $port = random_int(20000, 40000);
        $log = (string) tempnam(sys_get_temp_dir(), 'omnicrawl-site');
        $process = proc_open([\PHP_BINARY, '-S', '127.0.0.1:'.$port, __DIR__.'/../Fixtures/site/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['OMNICRAWL_SITE_MODE' => $mode, 'OMNICRAWL_SITE_LOG' => $log] + getenv());
        self::assertIsResource($process);
        for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); ++$i) {
            usleep(100_000);
        }
        self::$servers[] = [$process, $mode, $log];

        return 'http://127.0.0.1:'.$port;
    }

    /** @return list<string> */
    private static function log(int $server): array
    {
        return array_values(array_filter(explode("\n", (string) file_get_contents(self::$servers[$server][2]))));
    }
}
