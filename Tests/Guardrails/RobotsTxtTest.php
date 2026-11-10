<?php

namespace Omnicrawl\Tests\Guardrails;

use Omnicrawl\Robots\RobotsState;
use Omnicrawl\Robots\RobotsTxt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** robots.txt parsed and applied as RFC 9309 says, on the RFC's own examples first. */
final class RobotsTxtTest extends TestCase
{
    /** RFC 9309 §5.1, as printed. */
    private const RFC_EXAMPLE = <<<'TXT'
        User-Agent: *
        Disallow: *.gif$
        Disallow: /example/
        Allow: /publications/

        User-Agent: foobot
        Disallow:/
        Allow:/example/page.html
        Allow:/example/allowed.gif

        User-Agent: barbot
        User-Agent: bazbot
        Disallow: /example/page.html

        User-Agent: quxbot

        EOF
        TXT;

    /** @return iterable<string, array{string, string, bool}> */
    public static function rfcExample(): iterable
    {
        yield 'foobot: its group disallows everything' => ['foobot', '/', false];
        yield 'foobot: allowed page' => ['foobot', '/example/page.html', true];
        yield 'foobot: allowed gif, longer than "/"' => ['foobot', '/example/allowed.gif', true];
        yield 'foobot: another page' => ['foobot', '/example/other.html', false];
        yield 'foobot: the * group is not its own' => ['foobot', '/publications/x', false];
        yield 'barbot: disallowed page' => ['barbot', '/example/page.html', false];
        yield 'bazbot: same group as barbot' => ['bazbot', '/example/page.html', false];
        yield 'barbot: the * rules do not apply to it' => ['barbot', '/example/other.html', true];
        yield 'barbot: nor *.gif$' => ['barbot', '/a.gif', true];
        yield 'quxbot: a group with no rules allows everything' => ['quxbot', '/example/page.html', true];
        yield 'quxbot: not the * group either' => ['quxbot', '/x.gif', true];
        yield 'other: * group, *.gif$' => ['otherbot', '/images/x.gif', false];
        yield 'other: * group, .gif not at the end' => ['otherbot', '/images/x.gif?size=2', true];
        yield 'other: * group, /example/' => ['otherbot', '/example/page.html', false];
        yield 'other: * group, allowed' => ['otherbot', '/publications/2026', true];
        yield 'other: * group, nothing matches' => ['otherbot', '/about', true];
        yield 'product tokens match case-insensitively' => ['FooBot', '/example/page.html', true];
    }

    #[DataProvider('rfcExample')]
    public function testTheRfcsExample(string $token, string $path, bool $allowed): void
    {
        self::assertSame($allowed, RobotsTxt::parse(self::RFC_EXAMPLE)->allows($path, [$token]));
    }

    public function testTheLongestMatchWinsAndAnAllowWinsATie(): void
    {
        // RFC 9309 §5.2.
        $robots = RobotsTxt::parse("User-Agent: foobot\nAllow: /example/page/\nDisallow: /example/page/disallowed.gif\n");
        self::assertTrue($robots->allows('/example/page/', ['foobot']));
        self::assertTrue($robots->allows('/example/page/x.gif', ['foobot']));
        self::assertFalse($robots->allows('/example/page/disallowed.gif', ['foobot']));

        $tie = RobotsTxt::parse("User-agent: *\nDisallow: /page\nAllow: /page\n");
        self::assertTrue($tie->allows('/page', ['bot']), 'equivalent allow and disallow: the allow (§2.2.2)');
        self::assertSame('allow: /page', $tie->decidingRule('/page', ['bot']));
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function specialCharacters(): iterable
    {
        // RFC 9309 §2.2.3: "*" any sequence, "$" the end, "#" a comment.
        yield '"*" in the middle' => ['Disallow: /this/*/exactly', '/this/a/b/exactly/more', false];
        yield '"$" anchors' => ['Disallow: /this/path/exactly$', '/this/path/exactly', false];
        yield '"$" anchors: more after it' => ['Disallow: /this/path/exactly$', '/this/path/exactly/no', true];
        yield 'a comment is cut' => ['Disallow: /private # not /public', '/public', true];
        yield 'the query is matched' => ['Disallow: /*?session=', '/a/b?session=1', false];
        yield 'an empty disallow is no rule' => ['Disallow:', '/anything', true];
        yield 'a trailing "*" changes nothing' => ['Disallow: /fish*', '/fish.html', false];
        yield 'paths are case-sensitive' => ['Disallow: /Fish', '/fish', true];
    }

    #[DataProvider('specialCharacters')]
    public function testSpecialCharacters(string $rule, string $path, bool $allowed): void
    {
        self::assertSame($allowed, RobotsTxt::parse("User-agent: *\n".$rule."\n")->allows($path, ['bot']));
    }

    /** @return iterable<string, array{string, string}> */
    public static function percentEncoding(): iterable
    {
        // RFC 9309 §2.2.2's table: the rule as written, the path it matches.
        yield 'plain' => ['/foo/bar?baz=quz', '/foo/bar?baz=quz'];
        yield 'reserved characters of a query' => ['/foo/bar?baz=https://foo.bar', '/foo/bar?baz=https%3A%2F%2Ffoo.bar'];
        yield 'UTF-8 in the rule' => ['/foo/bar/ツ', '/foo/bar/%E3%83%84'];
        yield 'UTF-8 encoded in the rule' => ['/foo/bar/%E3%83%84', '/foo/bar/%E3%83%84'];
        yield 'unreserved characters encoded' => ['/foo/bar/%62%61%7A', '/foo/bar/baz'];
        yield 'lower-case hex' => ['/foo/bar/%e3%83%84', '/foo/bar/%E3%83%84'];
    }

    #[DataProvider('percentEncoding')]
    public function testPercentEncodingIsNormalisedOnBothSides(string $rule, string $path): void
    {
        self::assertFalse(RobotsTxt::parse("User-agent: *\nDisallow: ".$rule."\n")->allows($path, ['bot']));
    }

    public function testGroupsOfOneAgentAreCombinedAndTheWildcardIsTheFallback(): void
    {
        $robots = RobotsTxt::parse("User-agent: examplebot\nDisallow: /a/\n\nUser-agent: *\nDisallow: /\n\nUser-agent: ExampleBot\nDisallow: /b/\n");
        self::assertFalse($robots->allows('/a/1', ['ExampleBot']));
        self::assertFalse($robots->allows('/b/1', ['ExampleBot']), 'its two groups are one (§2.2.1)');
        self::assertTrue($robots->allows('/c/1', ['ExampleBot']), 'the * group does not apply to a bot that has its own');
        self::assertFalse($robots->allows('/c/1', ['Otherbot']));
        self::assertTrue(RobotsTxt::parse("User-agent: foobot\nDisallow: /\n")->allows('/x', ['other']), 'no group matches and no * group: no rule');
    }

    public function testTheSitesBotAndTheFamilyAreEachObeyed(): void
    {
        $tokens = ['ExampleBot', 'omnicrawl'];
        self::assertFalse(RobotsTxt::parse("User-agent: omnicrawl\nDisallow: /\n\nUser-agent: ExampleBot\nAllow: /\n")->allows('/page', $tokens), 'a site that refuses the family is obeyed though it allows the bot');
        self::assertFalse(RobotsTxt::parse("User-agent: ExampleBot\nDisallow: /\n\nUser-agent: omnicrawl\nAllow: /\n")->allows('/page', $tokens));
        self::assertTrue(RobotsTxt::parse("User-agent: omnicrawl\nAllow: /\n\nUser-agent: *\nDisallow: /\n")->allows('/page', $tokens), 'a group of its own sets the * group aside');
        self::assertFalse(RobotsTxt::parse("User-agent: *\nDisallow: /\n")->allows('/page', $tokens));
    }

    public function testRobotsTxtItselfIsAlwaysAllowed(): void
    {
        self::assertTrue(RobotsTxt::parse("User-agent: *\nDisallow: /\n")->allows('/robots.txt', ['bot']));
    }

    public function testUnknownLinesBomCaseAndCrawlDelay(): void
    {
        $robots = RobotsTxt::parse("\xEF\xBB\xBFUSER-AGENT: ExampleBot\nNoindex: /x\nDISALLOW: /private\nCrawl-delay: 4.5\nContent-Usage: train-ai=n\nnot a line\n\nuser-agent: *\ncrawl-delay: 30\nSitemap: https://example.org/sitemap.xml\n");
        self::assertFalse($robots->allows('/private/1', ['ExampleBot']));
        self::assertTrue($robots->allows('/x', ['ExampleBot']), 'an unknown line is no rule');
        self::assertSame(4.5, $robots->crawlDelay(['ExampleBot', 'omnicrawl']));
        self::assertSame(30.0, $robots->crawlDelay(['Other']));
        self::assertSame(['train-ai=n'], $robots->contentUsage(['ExampleBot']));
        self::assertSame(['https://example.org/sitemap.xml'], $robots->sitemaps);
    }

    public function testOnlyTheFirst500KibAreRead(): void
    {
        $robots = RobotsTxt::parse("User-agent: *\n".str_repeat("Allow: /padding\n", (int) ceil(RobotsTxt::MAX_BYTES / 16))."Disallow: /beyond\n");
        self::assertGreaterThanOrEqual(500 * 1024, RobotsTxt::MAX_BYTES, 'RFC 9309 §2.5: at least 500 KiB');
        self::assertTrue($robots->allows('/beyond', ['bot']));
    }

    public function testStatesWithoutABody(): void
    {
        self::assertSame(RobotsState::Unavailable, RobotsTxt::unavailable()->state);
        self::assertTrue(RobotsTxt::unavailable()->allows('/anything', ['bot']), '4xx: unavailable, crawl allowed (§2.3.1.3)');
        self::assertFalse(RobotsTxt::unreachable()->allows('/anything', ['bot']), '5xx or unreachable: complete disallow (§2.3.1.4)');
        self::assertTrue(RobotsTxt::overridden()->allows('/anything', ['bot']));
    }
}
