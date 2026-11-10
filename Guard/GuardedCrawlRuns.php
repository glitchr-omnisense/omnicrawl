<?php

namespace Omnicrawl\Guard;

use Omnicrawl\Exception\NotSupportedException;
use Omnicrawl\CrawlRunsInterface;
use Omnicrawl\Exception\InvalidArgumentException;
use Omnicrawl\Exception\RefusedException;
use Omnicrawl\Exception\UsageLimitReachedException;
use Omnicrawl\Model\CrawlRequest;
use Omnicrawl\Model\CrawlRun;
use Omnicrawl\Model\Operation;
use Omnicrawl\Model\Results;
use Omnicrawl\RunnerInterface;
use Omnisense\Contracts\Policy\PolicyGuard;

/**
 * Runs behind the gate. start() admits every address as "crawl-runs" -
 * the first refusal refuses the run, before the service is called
 * (Gate::refusal() sorts a list beforehand) - and checks each host's
 * limits for its share of the run (two pages of one host are two of its
 * requests), then the provider's ("start"); the shares are counted once
 * the service took the run. results() checks every page again as it is
 * read - the address asked and the one that answered, the page's own
 * signals - and lists the ones it drops with their reasons.
 */
final class GuardedCrawlRuns implements CrawlRunsInterface
{
    /** The most addresses one run takes. */
    public const MAX_URLS = 1000;

    public function __construct(
        private readonly RunnerInterface $provider,
        private readonly Gate $gate,
        private readonly PolicyGuard $guard,
    ) {
    }

    public function start(array $urls, bool $render = false): CrawlRun
    {
        $urls = array_values(array_unique($urls));
        if ([] === $urls || \count($urls) > self::MAX_URLS) {
            throw new InvalidArgumentException(\sprintf('A crawl run takes 1 to %d addresses, %d given.', self::MAX_URLS, \count($urls)));
        }
        if ($render && !$this->provider->capabilities()->rendering) {
            throw NotSupportedException::call($this->provider->getTitle(), 'render JavaScript');
        }
        $admissions = [];
        $shares = [];
        foreach ($urls as $url) {
            $admission = $this->gate->inspect($url, Operation::CrawlRuns, $this->provider, $render || $this->provider->capabilities()->alwaysRenders);
            $admissions[$admission->url] = $admission;
            $shares[$admission->host] = ($shares[$admission->host] ?? 0) + 1;
        }
        $first = [];
        foreach ($admissions as $admission) {
            if (!isset($first[$admission->host])) {
                $first[$admission->host] = $admission;
                $admission->hostGuard->check(Operation::CrawlRuns->value, [], ['requests' => $shares[$admission->host]]);
            }
        }
        $this->guard->check('start');
        try {
            $run = $this->provider->start(new CrawlRequest(array_keys($admissions), $this->gate->agent(), $render));
        } finally {
            $this->guard->record('start');
        }
        foreach ($first as $host => $admission) {
            $this->gate->count($admission, $shares[$host]);
        }

        return $run;
    }

    public function run(string $id): CrawlRun
    {
        return $this->guard->call('run', fn () => $this->provider->run($id));
    }

    public function results(string $id, ?string $cursor = null): Results
    {
        $page = $this->guard->call('results', fn () => $this->provider->results($id, $cursor));
        $records = [];
        $refused = [];
        foreach ($page->items as $fetched) {
            try {
                $records[] = $this->gate->record($this->gate->inspect($fetched->url, Operation::CrawlRuns, $this->provider), $fetched, $this->provider);
            } catch (RefusedException $e) {
                $refused[] = ['url' => $fetched->url, 'reason' => $e->reason, 'message' => $e->getMessage()];
            } catch (UsageLimitReachedException $e) {
                $refused[] = ['url' => $fetched->url, 'reason' => 'target-asked-to-wait', 'message' => $e->getMessage()];
            } catch (InvalidArgumentException $e) {
                $refused[] = ['url' => $fetched->url, 'reason' => 'invalid-address', 'message' => $e->getMessage()];
            }
        }

        return new Results($records, $refused, $page->next);
    }
}
