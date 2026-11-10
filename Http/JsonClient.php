<?php

namespace Omnicrawl\Http;

use Omnicrawl\Exception\ProviderException;
use Omnicrawl\Exception\UnreachableException;
use Omnisense\Contracts\Policy\Backoff;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A crawling service's JSON calls over the application's HTTP client:
 * omnisense/contracts' JsonClient with omnicrawl's exceptions - a 404 read
 * as "not known" (null), any other error status a ProviderException naming
 * the path only, a network failure an UnreachableException; the key kept
 * out of every message. Given the provider's Backoff, a 429 is a
 * UsageLimitReachedException with the wait asked.
 */
final class JsonClient extends \Omnisense\Contracts\Http\JsonClient
{
    /** @param array<string, string|list<string>> $headers sent with every request */
    public function __construct(HttpClientInterface $http, string $provider, array $headers = [], ?Backoff $backoff = null)
    {
        parent::__construct($http, $provider, $headers, $backoff);
    }

    public function get(string $url, array $query = [], array $headers = []): ?Data
    {
        return self::own(parent::get($url, $query, $headers));
    }

    public function request(string $method, string $url, array $options = [], array $headers = []): ?Data
    {
        return self::own(parent::request($method, $url, $options, $headers));
    }

    /** @param array<mixed> $data */
    protected function data(array $data): Data
    {
        return new Data($data);
    }

    protected function answered(string $what, int $status, string $detail): \Throwable
    {
        return ProviderException::answered($this->provider, $what, $status, $detail);
    }

    protected function unreachable(string $message, \Throwable $previous): \Throwable
    {
        return new UnreachableException($message, 0, $previous);
    }

    private static function own(?\Omnisense\Contracts\Http\Data $data): ?Data
    {
        return null === $data || $data instanceof Data ? $data : new Data($data->toArray());
    }
}
