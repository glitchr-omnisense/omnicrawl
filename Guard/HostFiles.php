<?php

namespace Omnicrawl\Guard;

use Omnicrawl\Agent;
use Omnicrawl\Robots\RobotsTxt;
use Omnicrawl\Tdm\TdmFile;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * What the gate reads of a host itself, before any provider is called and
 * whichever provider will be: its robots.txt and its
 * /.well-known/tdmrep.json, fetched with the family's User-Agent over the
 * application's HTTP client - never through the crawling service, whose
 * own reading (if it has one) is not the family's.
 *
 * robots.txt, per RFC 9309 §2.3 and §2.4: a 2xx is parsed; a 4xx is
 * "unavailable", any address allowed (§2.3.1.3) - but for a 429, read as
 * the server's own refusal and so as unreachable; a 5xx, a network error
 * or more than five redirects (§2.3.1.2 asks to follow five) is
 * "unreachable": complete disallow (§2.3.1.4; the RFC lets a crawler call
 * too many redirects unavailable, the family takes the stricter reading).
 * Kept ROBOTS_TTL (24 hours, §2.4's most), or less when Cache-Control's
 * max-age says less; an unreachable one UNREACHABLE_TTL, then asked again.
 *
 * tdmrep.json (W3C TDMRep Community Group Final Report, 2024-05-10): a 2xx
 * with a JSON list is read, anything else is no file. Kept 24 hours.
 */
final class HostFiles
{
    public const ROBOTS_TTL = 86400;

    public const UNREACHABLE_TTL = 3600;

    /** The least a robots.txt is kept, whatever its Cache-Control says: a host is not asked for it on every page. */
    public const MIN_TTL = 60;

    public const TDM_TTL = 86400;

    public const MAX_REDIRECTS = 5;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly Agent $agent,
        private readonly StoreInterface $store = new InMemoryStore(),
    ) {
    }

    /** A host's robots.txt; $origin is "https://example.org" or "http://example.org:8080". */
    public function robots(string $origin): RobotsTxt
    {
        $url = $origin.'/robots.txt';
        $kept = $this->store->get('robots '.$origin);
        if (null === $kept) {
            [$status, $body, $maxAge] = $this->read($url);
            $kept = ['status' => $status, 'body' => \is_int($status) && $status >= 200 && $status < 300 ? substr($body, 0, RobotsTxt::MAX_BYTES) : ''];
            $ok = \is_int($status) && $status >= 200 && $status < 500 && 429 !== $status && ($status < 300 || $status >= 400);
            $this->store->set('robots '.$origin, $kept, $ok ? max(self::MIN_TTL, min(self::ROBOTS_TTL, $maxAge ?? self::ROBOTS_TTL)) : self::UNREACHABLE_TTL);
        }
        $status = \is_int($kept['status'] ?? null) ? $kept['status'] : null;
        $body = \is_string($kept['body'] ?? null) ? $kept['body'] : '';

        return match (true) {
            null === $status, 429 === $status, $status >= 500, $status >= 300 && $status < 400 => RobotsTxt::unreachable($url),
            $status >= 400 => RobotsTxt::unavailable($url),
            $status >= 200 => RobotsTxt::parse($body, $url),
            default => RobotsTxt::unreachable($url),
        };
    }

    /** A host's /.well-known/tdmrep.json; an empty file when it has none or it cannot be read. */
    public function tdm(string $origin): TdmFile
    {
        $url = $origin.'/.well-known/tdmrep.json';
        $kept = $this->store->get('tdmrep '.$origin);
        if (null === $kept) {
            [$status, $body] = $this->read($url);
            $kept = ['found' => \is_int($status) && $status >= 200 && $status < 300, 'body' => \is_int($status) && $status >= 200 && $status < 300 ? substr($body, 0, RobotsTxt::MAX_BYTES) : ''];
            $this->store->set('tdmrep '.$origin, $kept, self::TDM_TTL);
        }

        return true === ($kept['found'] ?? false) && \is_string($kept['body'] ?? null) ? TdmFile::parse($kept['body'], $url) : TdmFile::none();
    }

    /** @return array{0: ?int, 1: string, 2: ?int} the status (null when the host could not be reached), the body, Cache-Control's max-age */
    private function read(string $url): array
    {
        try {
            $response = $this->http->request('GET', $url, [
                'headers' => ['User-Agent' => $this->agent->header(), 'Accept' => 'text/plain, application/json;q=0.9, */*;q=0.1'],
                'max_redirects' => self::MAX_REDIRECTS,
                'timeout' => 10,
            ]);
            $status = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $body = $response->getContent(false);
        } catch (ExceptionInterface) {
            return [null, '', null];
        }
        $maxAge = null;
        foreach ($headers['cache-control'] ?? [] as $value) {
            if (preg_match('~max-age=(\d+)~i', $value, $m)) {
                $maxAge = (int) $m[1];
            }
        }

        return [$status, $body, $maxAge];
    }
}
