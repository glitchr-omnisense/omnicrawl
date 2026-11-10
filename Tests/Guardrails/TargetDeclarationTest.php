<?php

namespace Omnicrawl\Tests\Guardrails;

use Omnicrawl\Agent;
use Omnicrawl\Direct\DirectFactory;
use Omnicrawl\Exception\InvalidConfigException;
use Omnicrawl\Guard\Gate;
use Omnicrawl\Model\Operation;
use Omnicrawl\Registry;
use Omnicrawl\Target\Target;
use Omnicrawl\Target\Targets;
use Omnicrawl\Testing\RecordedHttpClient;
use Omnicrawl\Tests\Kit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A declaration cannot say less than it claims, and the family cannot run without its identity. */
final class TargetDeclarationTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalid(): iterable
    {
        yield 'written permission without a reference' => [['target' => 'a.example.org', 'collection' => 'allowed', 'authority' => 'written-permission'], 'needs a reference'];
        yield 'published terms without a date' => [['target' => 'a.example.org', 'collection' => 'allowed', 'authority' => 'published-terms', 'reference' => 'https://a.example.org/terms'], 'needs a reference'];
        yield 'crawl runs on robots alone, unreferenced' => [['target' => 'a.example.org', 'collection' => 'allowed', 'authority' => 'robots-only', 'operations' => ['crawl-runs']], 'with crawl runs'];
        yield 'a refusal without a reference' => [['target' => 'a.example.org', 'collection' => 'refused', 'authority' => 'published-terms', 'reason' => 'terms'], 'a refusal says where'];
        yield 'a refusal without a reason' => [['target' => 'a.example.org', 'collection' => 'refused', 'authority' => 'published-terms', 'reference' => 'https://a.example.org/terms', 'date' => '2026-11-10'], 'without a reason'];
        yield 'robots set aside under published terms' => [['target' => 'a.example.org', 'collection' => 'allowed', 'authority' => 'published-terms', 'reference' => 'https://a.example.org/terms', 'date' => '2026-11-10', 'robots' => ['honour' => false, 'reason' => 'they said so']], 'only as one\'s own site or with written permission'];
        yield 'robots set aside without a reason' => [['target' => 'a.example.org', 'collection' => 'allowed', 'authority' => 'own-site', 'reference' => 'https://a.example.org', 'date' => '2026-11-10', 'robots' => ['honour' => false]], 'without a reason'];
        yield 'no limits but for one\'s own site' => [['target' => 'a.example.org', 'collection' => 'allowed', 'authority' => 'written-permission', 'reference' => 'DOC-1', 'date' => '2026-11-10', 'rateLimits' => []], 'only as one\'s own site'];
        yield 'an unknown key' => [['target' => 'a.example.org', 'collection' => 'allowed', 'authority' => 'robots-only', 'ignoreRobots' => true], 'does not know: ignoreRobots'];
        yield 'an unknown operation' => [['target' => 'a.example.org', 'collection' => 'allowed', 'authority' => 'robots-only', 'operations' => ['scrape-all']], 'scrape-all'];
        yield 'an unknown authority' => [['target' => 'a.example.org', 'collection' => 'allowed', 'authority' => 'fair-use'], 'needs "authority"'];
        yield 'a pattern that is no host' => [['target' => 'https://a.example.org', 'collection' => 'allowed', 'authority' => 'robots-only'], 'no host pattern'];
        yield 'a date that is no date' => [['target' => 'a.example.org', 'collection' => 'allowed', 'authority' => 'own-site', 'reference' => 'x', 'date' => '2026-13-45'], 'YYYY-MM-DD'];
    }

    /** @param array<string, mixed> $declaration */
    #[DataProvider('invalid')]
    public function testADeclarationThatSaysLessThanItClaimsIsRefused(array $declaration, string $message): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage($message);
        Target::fromArray($declaration);
    }

    public function testTheMostSpecificPatternDecides(): void
    {
        $targets = new Targets([
            ['target' => '*.example.org', 'collection' => 'allowed', 'authority' => 'robots-only'],
            ['target' => 'shop.example.org', 'collection' => 'refused', 'authority' => 'published-terms', 'reference' => 'https://shop.example.org/terms', 'date' => '2026-11-10', 'reason' => 'no bots'],
            ['target' => 'shop.example.org/press/', 'collection' => 'allowed', 'authority' => 'written-permission', 'reference' => 'Press office, 2026-11-02', 'date' => '2026-11-02'],
            ['target' => 'example.org:8443', 'collection' => 'allowed', 'authority' => 'own-site', 'reference' => 'https://example.org:8443', 'date' => '2026-11-10', 'rateLimits' => []],
        ]);

        self::assertSame('*.example.org', $targets->for('blog.example.org', '/')->pattern);
        self::assertSame(Targets::ANY, $targets->for('example.org', '/')->pattern, '*.example.org is not example.org itself');
        self::assertSame('shop.example.org', $targets->for('shop.example.org', '/cart')->pattern);
        self::assertSame('shop.example.org/press/', $targets->for('shop.example.org', '/press/2026')->pattern);
        self::assertSame('example.org:8443', $targets->for('example.org:8443', '/')->pattern);
        self::assertSame([], $targets->for('example.org:8443', '/')->rateLimits);
    }

    public function testDeclarationsReadFromAFile(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'targets');
        file_put_contents((string) $file, json_encode(['targets' => [['target' => 'docs.example.org', 'collection' => 'allowed', 'authority' => 'own-site', 'reference' => 'https://docs.example.org', 'date' => '2026-11-10', 'operations' => ['page-fetch', 'crawl-runs'], 'render' => true]]]));
        $targets = Targets::fromFile((string) $file);
        unlink((string) $file);

        self::assertTrue($targets->for('docs.example.org', '/')->allows(Operation::CrawlRuns));
        self::assertSame('docs.example.org', Target::fromArray($targets->declared()[0]->toArray())->pattern, 'a declaration reads back what it writes');
    }

    /** @return iterable<string, array{string}> */
    public static function anonymous(): iterable
    {
        yield 'no version' => ['ExampleBot (+https://example.org/bot)'];
        yield 'no contact' => ['ExampleBot/1.0'];
        yield 'a browser' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36 bot@example.org'];
        yield 'the family alone' => ['omnicrawl/1 (+https://example.org)'];
    }

    #[DataProvider('anonymous')]
    public function testAnAgentNamesItsBotAndAContact(string $userAgent): void
    {
        $this->expectException(InvalidConfigException::class);
        Agent::parse($userAgent);
    }

    public function testTheAgentCarriesTheFamilysToken(): void
    {
        $agent = Agent::parse(Kit::UA);
        self::assertSame(['ExampleBot', 'omnicrawl'], $agent->tokens());
        self::assertSame(Kit::UA.' omnicrawl/1', $agent->header());
        self::assertSame('https://example.org/bot', $agent->contact);
        self::assertSame('ExampleBot/2 (bot@example.org) omnicrawl/1', Agent::parse('ExampleBot/2 (bot@example.org) omnicrawl/1')->header());
    }

    public function testEveryFactoryRequiresTheUserAgent(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('user_agent');
        (new DirectFactory(RecordedHttpClient::routes([])))->create();
    }

    public function testAnApplicationCrawlsUnderOneIdentity(): void
    {
        $gate = new Gate(Agent::parse(Kit::UA), RecordedHttpClient::routes([]));
        $this->expectException(InvalidConfigException::class);
        new Registry([new DirectFactory(RecordedHttpClient::routes([]))], ['direct' => ['factory' => 'direct', 'options' => ['user_agent' => 'OtherBot/1.0 (other@example.org)']]], $gate);
    }
}
