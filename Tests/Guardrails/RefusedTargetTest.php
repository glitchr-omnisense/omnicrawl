<?php

namespace Omnicrawl\Tests\Guardrails;

use Omnicrawl\Capabilities;
use Omnicrawl\Exception\RefusedException;
use Omnicrawl\Exception\ReservedException;
use Omnicrawl\Exception\TargetRefusedException;
use Omnicrawl\Model\ExtractRequest;
use Omnicrawl\Target\Target;
use Omnicrawl\Target\Targets;
use Omnicrawl\Tests\Kit;
use PHPUnit\Framework\TestCase;

/** A refused target never reaches the provider - nor anything else: the gate does not even read its robots.txt. */
final class RefusedTargetTest extends TestCase
{
    private const REFUSED = [
        'target' => 'timetables.example.jp',
        'collection' => 'refused',
        'authority' => 'published-terms',
        'reference' => 'https://timetables.example.jp/terms',
        'date' => '2026-11-10',
        'reason' => 'Its terms forbid automated collection.',
    ];

    public function testADeclaredRefusalIsTypedAndNothingIsRequested(): void
    {
        $kit = new Kit([], [], [self::REFUSED]);
        $kit->registry->get('site');

        foreach ([
            static fn () => $kit->registry->pageFetch('site')->fetch('https://timetables.example.jp/line/1'),
            static fn () => $kit->registry->extract('site')->extract('https://timetables.example.jp/line/1'),
            static fn () => $kit->registry->crawlRuns('site')->start(['https://timetables.example.jp/line/1']),
        ] as $call) {
            try {
                $call();
                self::fail('A refused target was collected.');
            } catch (TargetRefusedException $e) {
                self::assertSame(RefusedException::REASON_DECLARED, $e->reason);
                self::assertSame('https://timetables.example.jp/terms', $e->reference);
                self::assertStringContainsString('Its terms forbid automated collection', $e->getMessage());
            }
        }
        self::assertSame([], $kit->calls(), 'no call reached the provider');
        self::assertSame([], $kit->gateRequests(), 'not even robots.txt was asked for');
    }

    public function testARailOperatorsTimetablesRefusedByTheApplicationsOwnDeclaration(): void
    {
        // The family ships no list of third-party sites: the application declares the ones it
        // refuses, with the terms' address and the day it read them (Fixtures/refused-targets.json).
        $declared = Targets::fromFile(__DIR__.'/../Fixtures/refused-targets.json')->declared();
        $kit = new Kit([], [], array_map(static fn (Target $t) => $t->toArray(), $declared));
        $kit->registry->get('site');

        try {
            $kit->registry->pageFetch('site')->fetch('https://transfer.navitime.biz/tokyu/pc/diagram/TrainDiagram?node=00000000');
            self::fail('The declared refusal was not applied.');
        } catch (TargetRefusedException $e) {
            self::assertSame(RefusedException::REASON_DECLARED, $e->reason);
            self::assertSame('https://transfer.navitime.biz/tokyu/pc/disclaimer/DisclaimerInformation', $e->reference);
        }
        self::assertSame([], $kit->calls());
        self::assertSame([], $kit->gateRequests());
        self::assertTrue((new Kit())->registry->gate()->targets()->for('transfer.navitime.biz', '/tokyu/pc/x')->allowed, 'nothing refuses it but the application\'s declaration');
    }

    public function testTheFamilyShipsNoListOfThirdPartySites(): void
    {
        self::assertFileDoesNotExist(\dirname(__DIR__, 2).'/resources/targets');
        self::assertSame([], (new Targets())->declared());
    }

    public function testAnOperationTheDeclarationDoesNotNameIsRefused(): void
    {
        $kit = new Kit(['~/robots\.txt$~' => [404, '']], [], [['target' => 'partner.example.org', 'collection' => 'allowed', 'authority' => 'written-permission', 'reference' => 'Contract 2026-14', 'date' => '2026-10-01', 'operations' => ['page-fetch']]]);
        $kit->registry->get('site');

        try {
            $kit->registry->extract('site')->extract('https://partner.example.org/catalogue');
            self::fail('An extraction the partner did not allow.');
        } catch (TargetRefusedException $e) {
            self::assertSame(RefusedException::REASON_OPERATION, $e->reason);
        }
        self::assertSame([], $kit->calls());
    }

    public function testAProviderThatEvadesBlocksIsUsedUnderALicenceOnly(): void
    {
        $evasive = new Capabilities(pageFetch: true, headers: true, evasion: ['ban-avoidance']);
        $kit = new Kit(['~/robots\.txt$~' => [404, '']], ['https://mine.example.org/' => [200, Kit::page()]], [['target' => 'mine.example.org', 'collection' => 'allowed', 'authority' => 'own-site', 'reference' => 'https://mine.example.org', 'date' => '2026-11-10']], $evasive);
        $kit->registry->get('site');

        try {
            $kit->registry->pageFetch('site')->fetch('https://news.example.com/1');
            self::fail('An evasive service was used on an undeclared site.');
        } catch (TargetRefusedException $e) {
            self::assertSame(RefusedException::REASON_PROVIDER, $e->reason);
        }
        self::assertSame([], $kit->calls());
        self::assertSame(200, $kit->registry->pageFetch('site')->fetch('https://mine.example.org/')->status, 'one\'s own site');
    }

    public function testATdmReservationRefusesExtractionNotAPreviewFetch(): void
    {
        $kit = new Kit(['~/robots\.txt$~' => [404, ''], '~tdmrep\.json$~' => [200, '[{"location": "/articles/*", "tdm-reservation": 1, "tdm-policy": "https://news.example.com/tdm-licence"}]']], ['https://news.example.com/articles/1' => [200, Kit::page()]]);
        $kit->registry->get('site');

        try {
            $kit->registry->extract('site')->extract('https://news.example.com/articles/1', new ExtractRequest(['h' => 'h1']));
            self::fail('A reserved page was mined.');
        } catch (ReservedException $e) {
            self::assertSame(RefusedException::REASON_TDM, $e->reason);
            self::assertSame('https://news.example.com/tdm-licence', $e->reference);
        }
        self::assertSame([], $kit->calls(), 'refused before the provider');

        $record = $kit->registry->pageFetch('site')->fetch('https://news.example.com/articles/1');
        self::assertTrue($record->signals->tdm->reserved, 'a page fetched is not mined, and its record says the reservation');
        self::assertSame('tdmrep.json', $record->signals->tdm->source);
    }
}
