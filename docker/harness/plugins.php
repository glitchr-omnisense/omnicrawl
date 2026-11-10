<?php

/*
 * The omnicrawl/* providers the harness installs beside the core: their
 * tests' namespace, and what their tests need besides (require-dev).
 */
return [
    'firecrawl' => ['Omnicrawl\\Firecrawl\\Tests\\'],
    'scrapingbee' => ['Omnicrawl\\ScrapingBee\\Tests\\'],
    'zyte' => ['Omnicrawl\\Zyte\\Tests\\'],
    'apify' => ['Omnicrawl\\Apify\\Tests\\'],
];
