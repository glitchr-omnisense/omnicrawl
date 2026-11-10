<?php

namespace Omnicrawl\Tests;

use Omnicrawl\Exception\InvalidArgumentException;
use Omnicrawl\Extract\Selector;
use Omnicrawl\Model\ExtractRequest;
use PHPUnit\Framework\TestCase;

/** Metadata, Open Graph, JSON-LD and selectors read from a page, the same whichever provider fetched it. */
final class ExtractorTest extends TestCase
{
    public function testAPagesMetadataAndFields(): void
    {
        $page = <<<'HTML'
            <!doctype html><html lang="fr"><head>
            <meta charset="utf-8"><title> Le  titre </title>
            <meta name="description" content="La description">
            <link rel="canonical" href="/articles/1">
            <meta property="og:title" content="Titre OG">
            <meta property="og:image" content="/img/cover.jpg">
            <meta property="og:image" content="https://cdn.example.com/2.jpg">
            <meta property="og:site_name" content="Exemple">
            <meta name="twitter:card" content="summary_large_image">
            <script type="application/ld+json">{"@context": "https://schema.org", "@type": "NewsArticle", "headline": "Titre"}</script>
            <script type="application/ld+json">{not json</script>
            </head><body>
            <ul class="items"><li class="item first">Un</li><li class="item">Deux</li></ul>
            <a id="next" href="/articles/2" data-kind="next">Suite</a>
            <img class="logo" src="logo.png">
            </body></html>
            HTML;
        $kit = new Kit(['~/robots\.txt$~' => [404, '']], ['https://news.example.com/articles/1' => [200, $page]]);

        $extraction = $kit->registry->extract('site')->extract('https://news.example.com/articles/1', new ExtractRequest([
            'items' => 'ul.items > li.item',
            'first' => '.first',
            'next' => 'a#next@href',
            'kind' => '[data-kind=next]',
            'logo' => 'img.logo@src',
            'h' => 'xpath://li[2]',
            'none' => 'table td',
        ]));
        $m = $extraction->metadata;

        self::assertSame('Le titre', $m->htmlTitle);
        self::assertSame('Titre OG', $m->title());
        self::assertSame('La description', $m->description());
        self::assertSame('https://news.example.com/articles/1', $m->canonical);
        self::assertSame('fr', $m->language);
        self::assertSame(['https://news.example.com/img/cover.jpg', 'https://cdn.example.com/2.jpg'], $m->openGraph['og:image']);
        self::assertSame('summary_large_image', $m->twitter['twitter:card']);
        self::assertSame([['@context' => 'https://schema.org', '@type' => 'NewsArticle', 'headline' => 'Titre']], $m->jsonLd);
        self::assertSame(['Un', 'Deux'], $extraction->fields['items']);
        self::assertSame('Un', $extraction->first('first'));
        self::assertSame('/articles/2', $extraction->first('next'));
        self::assertSame('Suite', $extraction->first('kind'));
        self::assertSame('logo.png', $extraction->first('logo'));
        self::assertSame('Deux', $extraction->first('h'));
        self::assertSame([], $extraction->fields['none']);
        self::assertSame(['title' => 'Titre OG', 'description' => 'La description', 'image' => 'https://news.example.com/img/cover.jpg', 'siteName' => 'Exemple', 'url' => 'https://news.example.com/articles/1'], \array_slice($extraction->preview(), 0, 5));
        self::assertSame('robots-only', $extraction->preview()['provenance']['authority']);
    }

    public function testSelectorsBeyondTheSubsetAskForXpath(): void
    {
        self::assertSame('//div[@id="a"]//p | //span', Selector::parse('div#a p, span')->xpath);
        $this->expectException(InvalidArgumentException::class);
        Selector::parse('li:nth-child(2)');
    }
}
