<?php

namespace Omnicrawl\Tests\Guardrails;

use Omnicrawl\Capabilities;
use Omnicrawl\Exception\RefusedException;
use Omnicrawl\Exception\ReservedException;
use Omnicrawl\Model\Authority;
use Omnicrawl\Model\ExtractRequest;
use Omnicrawl\Model\Fetched;
use Omnicrawl\Model\FetchRequest;
use Omnicrawl\Model\Operation;
use Omnicrawl\Model\Record;
use Omnicrawl\Robots\RobotsState;
use Omnicrawl\Tests\Kit;
use Omnisense\Contracts\Policy\DeclaredBy;
use Omnisense\Contracts\Policy\Permission;
use PHPUnit\Framework\TestCase;

/** Every record says where it comes from and on what authority; the page's own signals are read and honoured. */
final class ProvenanceTest extends TestCase
{
    private const PARTNER = ['target' => 'docs.partner.example.org', 'collection' => 'allowed', 'authority' => 'written-permission', 'reference' => 'Contract 2026-14, clause 3', 'date' => '2026-10-01', 'operations' => ['page-fetch', 'extract', 'crawl-runs'], 'rateLimits' => [['max' => 60, 'window' => 'minute']], 'rights' => ['storage' => 'allowed', 'redistribution' => 'forbidden']];

    private static function assertProvenance(Record $record, string $url, Operation $operation): void
    {
        $p = $record->provenance;
        self::assertSame($url, $p->sourceUrl);
        self::assertSame($url, $p->finalUrl);
        self::assertSame('2027-01-15T08:00:00+00:00', $p->fetchedAt->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM));
        self::assertSame('fixed', $p->provider);
        self::assertSame($operation, $p->operation);
        self::assertSame(Authority::WrittenPermission, $p->authority);
        self::assertSame('docs.partner.example.org', $p->target);
        self::assertSame('Contract 2026-14, clause 3', $p->reference);
        self::assertSame('2026-10-01', $p->declaredAt);
        self::assertSame(DeclaredBy::Site, $p->declaredBy);
        self::assertSame(RobotsState::Unavailable, $p->robots);
        self::assertSame(Kit::UA.' omnicrawl/1', $p->userAgent);
        self::assertTrue($record->mayStore());
        self::assertFalse($record->mayRepublish());
        self::assertSame('Contract 2026-14, clause 3', $record->provenance->toArray()['reference']);
    }

    public function testEveryOperationsRecordsCarryTheirProvenance(): void
    {
        $pages = ['https://docs.partner.example.org/a' => [200, Kit::page('A')], 'https://docs.partner.example.org/b' => [200, Kit::page('B')]];
        $kit = new Kit(['~/robots\.txt$~' => [404, '']], $pages, [self::PARTNER]);
        $kit->now = (float) strtotime('2027-01-15T08:00:00Z');

        self::assertProvenance($kit->registry->pageFetch('site')->fetch('https://docs.partner.example.org/a'), 'https://docs.partner.example.org/a', Operation::PageFetch);
        self::assertProvenance($kit->registry->extract('site')->extract('https://docs.partner.example.org/b')->record, 'https://docs.partner.example.org/b', Operation::Extract);
        $runs = $kit->registry->crawlRuns('site');
        $run = $runs->start(array_keys($pages));
        $results = $runs->results($run->id);
        self::assertCount(2, $results->records);
        foreach ($results->records as $record) {
            self::assertProvenance($record, $record->url, Operation::CrawlRuns);
        }
    }

    public function testTheProviderIsGivenTheFamilysAgent(): void
    {
        $kit = new Kit(['~/robots\.txt$~' => [404, '']], ['https://docs.partner.example.org/a' => [200, Kit::page()]], [self::PARTNER]);
        $kit->registry->pageFetch('site')->fetch('https://docs.partner.example.org/a');

        $request = $kit->factory->last?->requests[0];
        self::assertInstanceOf(FetchRequest::class, $request);
        self::assertSame(Kit::UA.' omnicrawl/1', $request->agent->header());
    }

    public function testRobotsDirectivesAddressedToTheFamilyAreRead(): void
    {
        $pages = [
            'https://news.example.com/header' => [200, Kit::page(), ['content-type' => ['text/html'], 'x-robots-tag' => ['noarchive, nosnippet', 'otherbot: noindex', 'omnicrawl: nofollow', 'unavailable_after: 2030-01-01']]],
            'https://news.example.com/meta' => [200, Kit::page('M', '<meta name="robots" content="noindex, noarchive"><meta name="examplebot" content="noimageindex"><meta name="googlebot" content="none"><meta name="description" content="Hidden">')],
        ];
        $kit = new Kit(['~/robots\.txt$~' => [404, '']], $pages);

        $header = $kit->registry->pageFetch('site')->fetch('https://news.example.com/header');
        self::assertSame(['noarchive', 'nosnippet', 'nofollow', 'unavailable_after: 2030-01-01'], $header->signals->directives);
        self::assertFalse($header->signals->noindex(), 'a directive for another bot does not apply');
        self::assertTrue($header->signals->noarchive());
        self::assertSame(Permission::Forbidden, $header->rights->storage, 'noarchive: no copy kept');
        self::assertSame(0, $header->rights->caching->ttl(3600));

        $kit->now += 10;
        $meta = $kit->registry->extract('site')->extract('https://news.example.com/meta');
        self::assertSame(['noindex', 'noarchive', 'noimageindex'], $meta->record->signals->directives);
        self::assertSame('Hidden', $meta->metadata->description());
        $kit->now += 10;
        $snippetless = $kit->registry->extract('site')->extract('https://news.example.com/header');
        self::assertNull($snippetless->preview()['description'], 'nosnippet: no description in a preview');
    }

    public function testAProviderWithoutHeadersLeavesTheirSignalsUnknown(): void
    {
        $kit = new Kit(['~/robots\.txt$~' => [404, '']], [], [], new Capabilities(pageFetch: true));
        $record = $kit->registry->gate()->record(
            $kit->registry->gate()->admit('https://news.example.com/', Operation::PageFetch, $kit->registry->get('site')),
            new Fetched('https://news.example.com/', 'https://news.example.com/', 200, Kit::page(), null),
            $kit->registry->get('site'),
        );

        self::assertFalse($record->signals->headersSeen);
    }

    public function testAReservationInThePageRefusesItsExtraction(): void
    {
        $page = Kit::page('R', '<meta name="tdm-reservation" content="1"><meta name="tdm-policy" content="https://news.example.com/policy.json">');
        $kit = new Kit(['~/robots\.txt$~' => [404, '']], ['https://news.example.com/r' => [200, $page], 'https://news.example.com/h' => [200, Kit::page(), ['content-type' => ['text/html'], 'tdm-reservation' => ['1']]], 'https://news.example.com/z' => [200, $page, ['content-type' => ['text/html'], 'tdm-reservation' => ['0']]]]);

        foreach (['r' => 'meta', 'h' => 'header'] as $path => $source) {
            try {
                $kit->registry->extract('site')->extract('https://news.example.com/'.$path, new ExtractRequest());
                self::fail('A page that reserves mining was extracted.');
            } catch (ReservedException $e) {
                self::assertSame(RefusedException::REASON_TDM, $e->reason);
                self::assertStringContainsString($source, $e->getMessage());
            }
            $kit->now += 10;
        }
        self::assertTrue($kit->registry->pageFetch('site')->fetch('https://news.example.com/z')->signals->tdm->reserved, 'the meta, read after the header, prevails (TDMRep §4)');
    }

    public function testARedirectToARefusedAddressIsRefused(): void
    {
        $kit = new Kit(['~news\.example\.com/robots\.txt$~' => [404, ''], '~private\.example\.com/robots\.txt$~' => [200, "User-agent: *\nDisallow: /\n"]], ['https://news.example.com/go' => [200, Kit::page(), ['content-type' => ['text/html']], 'https://private.example.com/landing']]);

        try {
            $kit->registry->pageFetch('site')->fetch('https://news.example.com/go');
            self::fail('A redirect to a disallowed address was kept.');
        } catch (ReservedException $e) {
            self::assertSame(RefusedException::REASON_REDIRECT, $e->reason);
            self::assertStringContainsString('private.example.com/robots.txt', $e->getMessage());
        }
    }

    public function testARunsResultsAreCheckedAgainAndTheDroppedOnesListed(): void
    {
        $kit = new Kit(
            ['~/robots\.txt$~' => [200, "User-agent: *\nDisallow: /secret/\n"]],
            ['https://docs.partner.example.org/a' => [200, Kit::page()], 'https://docs.partner.example.org/secret/b' => [200, Kit::page()], 'https://elsewhere.example.net/c' => [200, Kit::page()]],
            [self::PARTNER],
            extra: ['https://docs.partner.example.org/secret/b', 'https://elsewhere.example.net/c'],
        );
        $runs = $kit->registry->crawlRuns('site');
        $results = $runs->results($runs->start(['https://docs.partner.example.org/a'])->id);

        self::assertSame(['https://docs.partner.example.org/a'], array_map(static fn (Record $r) => $r->url, $results->records));
        self::assertSame([['https://docs.partner.example.org/secret/b', RefusedException::REASON_ROBOTS], ['https://elsewhere.example.net/c', RefusedException::REASON_OPERATION]], array_map(static fn (array $r) => [$r['url'], $r['reason']], $results->refused));
    }
}
