<?php

/*
 * The harness's local test site (php -S 127.0.0.1:<port> router.php): the
 * only site the live tests reach. OMNICRAWL_SITE_MODE=down makes its
 * robots.txt answer 503. Every request is logged, so that a test can show
 * what was never asked.
 */
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = (string) parse_url(is_string($uri) ? $uri : '/', \PHP_URL_PATH);
$agent = $_SERVER['HTTP_USER_AGENT'] ?? '-';
$mode = getenv('OMNICRAWL_SITE_MODE') ?: 'up';
file_put_contents((string) getenv('OMNICRAWL_SITE_LOG'), $path.' '.(is_string($agent) ? $agent : '-')."\n", \FILE_APPEND);
$page = static fn (string $title, string $head = '') => '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>'.$title.'</title>'.$head.'</head><body><h1>'.$title.'</h1></body></html>';

switch (true) {
    case '/robots.txt' === $path && 'down' === $mode:
        http_response_code(503);
        echo 'Service unavailable';
        break;
    case '/robots.txt' === $path:
        header('Content-Type: text/plain');
        echo "User-agent: *\nDisallow: /private/\nCrawl-delay: 1\n\nUser-agent: omnicrawl\nDisallow: /private/\nDisallow: /no-family/\nCrawl-delay: 1\n";
        break;
    case '/.well-known/tdmrep.json' === $path:
        header('Content-Type: application/json');
        echo '[{"location": "/reserved/*", "tdm-reservation": 1, "tdm-policy": "https://example.org/licence"}]';
        break;
    case '/article' === $path:
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noarchive');
        echo $page('Local article', '<meta property="og:title" content="A local article"><meta property="og:image" content="/cover.png"><meta name="description" content="Served by the harness">');
        break;
    case '/redirect' === $path:
        header('Location: /private/landing', true, 302);
        break;
    case '/slow-down' === $path:
        http_response_code(429);
        header('Retry-After: 120');
        break;
    default:
        header('Content-Type: text/html; charset=utf-8');
        echo $page('Page '.$path);
}
