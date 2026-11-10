<?php

namespace Omnicrawl\Direct;

use Omnicrawl\Capabilities;
use Omnicrawl\Exception\NotSupportedException;
use Omnicrawl\Exception\UnreachableException;
use Omnicrawl\Extract\Extractor;
use Omnicrawl\FetcherInterface;
use Omnicrawl\Http\JsonClient;
use Omnicrawl\Model\FetchRequest;
use Omnicrawl\Model\Fetched;
use Omnisense\Contracts\Policy\Caching;
use Omnisense\Contracts\Policy\Permission;
use Omnisense\Contracts\Policy\UsagePolicy;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One GET to the target with the family's User-Agent, at most five
 * redirects followed - one by one, each admitted by the gate before it is
 * requested (FetchRequest::follows()) -, the body kept up to max_bytes; the target's status,
 * headers and final address as they came. No JavaScript (render refused),
 * no proxy, no retry: what the target answers is what the record says.
 * Its own policy sets no limit - there is no service between the
 * application and the target - and the target's limits are the gate's.
 */
final class DirectProvider implements FetcherInterface
{
    public const MAX_BYTES = 5242880;

    /** The redirects followed at most, each one admitted by the gate before it is requested (RFC 9309 asks five for robots.txt; the same for pages). */
    public const MAX_REDIRECTS = 5;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly float $timeout = 15.0,
        private readonly int $maxBytes = self::MAX_BYTES,
    ) {
    }

    public function getName(): string
    {
        return 'direct';
    }

    public function getTitle(): string
    {
        return 'Direct HTTP';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(pageFetch: true, headers: true, policy: UsagePolicy::builder('direct')
            ->noLimits()
            ->commercial(Permission::Allowed)
            ->openSourceRequired(false)
            ->caching(new Caching(Permission::Allowed))
            ->storage(Permission::Allowed)
            ->redistribution(Permission::Allowed)
            ->requireUserAgent('~^[A-Za-z_-]+/\S+.*(?:https?://|@)~')
            ->provenance(notes: 'No service between the application and the target: the provider adds no term of its own. What may be done with a page is the target\'s to say, through its declaration and its signals, on each record.')
            ->build());
    }

    public function fetch(FetchRequest $request): Fetched
    {
        if ($request->render) {
            throw NotSupportedException::call('The direct provider', 'render JavaScript');
        }
        $url = $request->url;
        for ($hop = 0; ; ++$hop) {
            [$status, $headers, $body] = $this->get($url, $request);
            $location = $headers['location'][0] ?? null;
            if ($status < 300 || $status >= 400 || null === $location || $hop >= self::MAX_REDIRECTS) {
                break;
            }
            $next = Extractor::absolute($location, $url);
            if (!$request->follows($next)) {
                // Not requested: the gate refuses the record, the address it led to named.
                return new Fetched($request->url, $next, $status, '', Fetched::normaliseHeaders($headers), false, false);
            }
            $url = $next;
        }
        $final = $url;
        $type = strtolower($headers['content-type'][0] ?? 'text/html');

        return new Fetched($request->url, $final, $status, $body, Fetched::normaliseHeaders($headers), false, str_contains($type, 'html'));
    }

    /** @return array{0: int, 1: array<string, list<string>>, 2: string} */
    private function get(string $url, FetchRequest $request): array
    {
        try {
            $response = $this->http->request('GET', $url, [
                'headers' => ['User-Agent' => $request->agent->header(), 'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5'],
                'max_redirects' => 0,
                'timeout' => $this->timeout,
            ]);

            return [$response->getStatusCode(), $response->getHeaders(false), substr($response->getContent(false), 0, $this->maxBytes)];
        } catch (ExceptionInterface $e) {
            throw new UnreachableException(\sprintf('%s could not be reached: %s', parse_url($url, \PHP_URL_HOST) ?: 'The target', JsonClient::reason($e->getMessage())), 0);
        }
    }
}
