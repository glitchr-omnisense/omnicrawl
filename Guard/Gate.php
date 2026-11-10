<?php

namespace Omnicrawl\Guard;

use Omnicrawl\Agent;
use Omnicrawl\Exception\InvalidArgumentException;
use Omnicrawl\Exception\RefusedException;
use Omnicrawl\Exception\ReservedException;
use Omnicrawl\Exception\RobotsDisallowedException;
use Omnicrawl\Exception\TargetRefusedException;
use Omnicrawl\Exception\UsageLimitReachedException;
use Omnicrawl\Model\Fetched;
use Omnicrawl\Model\Operation;
use Omnicrawl\Model\Provenance;
use Omnicrawl\Model\Record;
use Omnicrawl\ProviderInterface;
use Omnicrawl\Robots\RobotsState;
use Omnicrawl\Robots\RobotsTxt;
use Omnicrawl\Target\Targets;
use Omnisense\Contracts\Policy\InMemoryRateLimiter;
use Omnisense\Contracts\Policy\PolicyGuard;
use Omnisense\Contracts\Policy\RateLimiterInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The family's guardrails, applied by the core before any provider is
 * called and on everything a provider gives back. admit() decides, in
 * this order, and throws at the first refusal:
 *
 *  1. the address is http(s), with a host and no credentials in it;
 *  2. its target (the most specific declaration, or the default) allows
 *     collection - TargetRefusedException otherwise;
 *  3. ... and allows the operation, and rendering when it is asked;
 *  4. a provider that evades blocks by itself (Capabilities::$evasion) is
 *     used under a licence only (own site, written permission);
 *  5. robots.txt, read by the gate itself with the family's User-Agent,
 *     allows the path for the family's tokens (RobotsDisallowedException
 *     otherwise, an unreachable robots.txt included), unless the target
 *     sets it aside under a licence with a written reason;
 *  6. an extraction or a run is not text and data mining a site reserved
 *     in its /.well-known/tdmrep.json, unless under a licence
 *     (ReservedException);
 *  7. the host's limits - the target's declared ones or the default's,
 *     and robots.txt's Crawl-delay as one request every so many seconds -
 *     have room for the requests (UsageLimitReachedException).
 *
 * The provider's own limits are the registry's guard, checked next by the
 * guarded wrappers: both apply, and so the stricter wins. record() makes
 * the provider's answer a Record: the address that finally answered
 * checked as the one asked was (a redirect to a refused address is
 * refused), the page's signals read (X-Robots-Tag, meta robots, TDM
 * reservation, Content-Usage), a TDM reservation in the page itself
 * honoured for an extraction or a run, noarchive making its rights forbid
 * storing it, a 429 or a Retry-After of the target holding the host, and
 * its provenance written.
 */
final class Gate
{
    private readonly HostFiles $files;

    private readonly SignalReader $signals;

    /** @var array<string, PolicyGuard> */
    private array $guards = [];

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param ?\Closure(): \DateTimeImmutable $clock */
    public function __construct(
        private readonly Agent $agent,
        HttpClientInterface $http,
        private readonly Targets $targets = new Targets(),
        StoreInterface $store = new InMemoryStore(),
        private readonly RateLimiterInterface $limiter = new InMemoryRateLimiter(),
        ?\Closure $clock = null,
    ) {
        $this->files = new HostFiles($http, $agent, $store);
        $this->signals = new SignalReader($agent);
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    public function agent(): Agent
    {
        return $this->agent;
    }

    public function targets(): Targets
    {
        return $this->targets;
    }

    public function limiter(): RateLimiterInterface
    {
        return $this->limiter;
    }

    /**
     * Everything an address must pass before a provider is asked for it,
     * the host's limits checked for $requests requests (not counted yet:
     * count() does, once the provider was called).
     *
     * @throws RefusedException
     * @throws UsageLimitReachedException
     */
    public function admit(string $url, Operation $operation, ProviderInterface $provider, bool $render = false, int $requests = 1): Admission
    {
        $admission = $this->inspect($url, $operation, $provider, $render);
        $admission->hostGuard->check($operation->value, [], ['requests' => $requests]);

        return $admission;
    }

    /** The same checks as admit() but the limits: a result read back, the address a redirect led to. */
    public function inspect(string $url, Operation $operation, ProviderInterface $provider, bool $render = false): Admission
    {
        [$url, $origin, $host, $path] = self::split($url);
        $target = $this->targets->for($host, $path);
        $reference = $target->reference;
        if (!$target->allowed) {
            throw new TargetRefusedException(\sprintf('omnicrawl refuses %s: %s (%s, %s).', $url, $target->reason ?? 'declared as refused', $target->authority->value, $reference ?? 'no reference'), $url, RefusedException::REASON_DECLARED, $reference);
        }
        if (!$target->allows($operation)) {
            throw new TargetRefusedException(\sprintf('omnicrawl refuses %s for %s: %s allows %s only%s.', $url, $operation->value, Targets::ANY === $target->pattern ? 'an undeclared site' : 'the target "'.$target->pattern.'"', implode(', ', array_map(static fn (Operation $o) => $o->value, $target->operations)) ?: 'nothing', Targets::ANY === $target->pattern ? ' (declare the target to run over it)' : ''), $url, RefusedException::REASON_OPERATION, $reference);
        }
        if ($render && !$target->render) {
            throw new TargetRefusedException(\sprintf('omnicrawl refuses to render %s: %s does not allow JavaScript rendering.', $url, Targets::ANY === $target->pattern ? 'an undeclared site' : 'the target "'.$target->pattern.'"'), $url, RefusedException::REASON_RENDERING, $reference);
        }
        $evasion = $provider->capabilities()->evasion;
        if ([] !== $evasion && !$target->authority->isLicence()) {
            throw new TargetRefusedException(\sprintf('omnicrawl refuses %s through %s: the service %s by itself, which only a licence (own site, written permission) covers; %s is %s.', $url, $provider->getTitle(), implode(', ', $evasion), Targets::ANY === $target->pattern ? 'an undeclared site' : 'the target "'.$target->pattern.'"', $target->authority->value), $url, RefusedException::REASON_PROVIDER, $reference);
        }
        $robots = $target->honourRobots ? $this->files->robots($origin) : RobotsTxt::overridden();
        if (!$robots->allows($path, $this->agent->tokens())) {
            $unreachable = RobotsState::Unreachable === $robots->state;
            throw new RobotsDisallowedException($unreachable
                ? \sprintf('omnicrawl refuses %s: %s could not be read (a server error, the host unreachable or too many redirects), which RFC 9309 reads as complete disallow.', $url, $robots->url ?? 'robots.txt')
                : \sprintf('omnicrawl refuses %s: %s disallows it for %s (%s).', $url, $robots->url ?? 'robots.txt', implode(', ', $this->agent->tokens()), $robots->decidingRule($path, $this->agent->tokens()) ?? 'disallow'), $url, $unreachable ? RefusedException::REASON_ROBOTS_UNREACHABLE : RefusedException::REASON_ROBOTS, $robots->url);
        }
        $tdm = $this->files->tdm($origin)->for($path);
        if (true === $tdm->reserved && $operation->mines() && !$target->authority->isLicence()) {
            throw new ReservedException(\sprintf('omnicrawl refuses %s for %s: the site reserves text and data mining (TDMRep, %s)%s; only a licence lifts it.', $url, $operation->value, $tdm->source ?? 'tdmrep.json', null === $tdm->policy ? '' : ', licences offered at '.$tdm->policy), $url, RefusedException::REASON_TDM, $tdm->policy ?? $origin.'/.well-known/tdmrep.json');
        }
        $delay = RobotsState::Parsed === $robots->state ? $robots->crawlDelay($this->agent->tokens()) : null;
        $key = $host."\0".$target->pattern."\0".($delay ?? '');

        return new Admission($url, $origin, $host, $operation, $target, $robots, $tdm, $this->guards[$key] ??= new PolicyGuard($target->usagePolicy($host, $delay), $this->limiter, null, UsageLimitReachedException::class), $render);
    }

    /** The requests made for an admitted address, counted against its host's limits. */
    public function count(Admission $admission, int $requests = 1): void
    {
        $admission->hostGuard->record($admission->operation->value, [], ['requests' => $requests]);
    }

    /**
     * The provider's answer, made a Record - or refused: the address it
     * finally answered from is refused, or the page reserves text and data
     * mining for an extraction or a run.
     *
     * @throws RefusedException
     * @throws UsageLimitReachedException the target answered 429 (its host is held for its Retry-After)
     */
    public function record(Admission $admission, Fetched $fetched, ProviderInterface $provider): Record
    {
        if (null !== $fetched->headers) {
            $admission->hostGuard->backoff()->observe($fetched->status, $fetched->headers);
        }
        $final = $admission;
        if ('' !== $fetched->finalUrl && self::split($fetched->finalUrl)[0] !== $admission->url) {
            try {
                $final = $this->inspect($fetched->finalUrl, $admission->operation, $provider, $admission->render);
            } catch (RefusedException $e) {
                throw new ReservedException(\sprintf('omnicrawl refuses what %s redirected to: %s', $admission->url, $e->getMessage()), $admission->url, RefusedException::REASON_REDIRECT, $e->reference, $e);
            }
        }
        $signals = $this->signals->read($fetched, $final->tdm, $final->robots);
        $target = $final->target;
        if (true === $signals->tdm->reserved && $admission->operation->mines() && !$target->authority->isLicence()) {
            throw new ReservedException(\sprintf('omnicrawl refuses %s for %s: the page reserves text and data mining (TDMRep, %s); only a licence lifts it.', $admission->url, $admission->operation->value, $signals->tdm->source ?? 'meta'), $admission->url, RefusedException::REASON_TDM, $signals->tdm->policy);
        }
        $declared = Targets::ANY !== $target->pattern;

        return new Record(
            $admission->url,
            $final->url,
            $fetched->status,
            $fetched->body,
            $fetched->headers,
            $fetched->rendered,
            $fetched->html,
            new Provenance(
                $admission->url,
                $final->url,
                ($this->clock)(),
                $provider->getName(),
                $admission->operation,
                $target->authority,
                $declared ? $target->pattern : null,
                $target->reference,
                $target->date,
                $target->declaredBy,
                $final->robots->state,
                $final->robots->url,
                $target->honourRobots ? null : $target->robotsReason,
                $this->agent->header(),
            ),
            $signals,
            $signals->noarchive() ? $target->rightsWithoutArchive() : $target->rights,
        );
    }

    /**
     * Whether an address would be admitted now for an operation, and if not
     * the refusal - a run's addresses sorted before it starts. Limits are
     * not asked.
     */
    public function refusal(string $url, Operation $operation, ProviderInterface $provider, bool $render = false): ?RefusedException
    {
        try {
            $this->inspect($url, $operation, $provider, $render);

            return null;
        } catch (RefusedException $e) {
            return $e;
        }
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} the address without its fragment, its origin, host (port included when not the scheme's), path and query */
    private static function split(string $url): array
    {
        $parts = parse_url(trim($url));
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host']) || !\in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new InvalidArgumentException(\sprintf('omnicrawl fetches http(s) addresses with a host, not "%s".', $url));
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('omnicrawl takes no credentials in an address: a site\'s login is not the family\'s to use.');
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) && !(('http' === $scheme && 80 === $parts['port']) || ('https' === $scheme && 443 === $parts['port'])) ? ':'.$parts['port'] : '';
        $path = ($parts['path'] ?? '') ?: '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        $origin = $scheme.'://'.$host.$port;

        return [$origin.$path.$query, $origin, $host.$port, $path.$query];
    }
}
