<?php

namespace Omnicrawl\Testing;

use Omnicrawl\Capabilities;
use Omnicrawl\FetcherInterface;
use Omnicrawl\Model\CrawlRequest;
use Omnicrawl\Model\CrawlRun;
use Omnicrawl\Model\FetchedPage;
use Omnicrawl\Model\FetchRequest;
use Omnicrawl\Model\Fetched;
use Omnicrawl\Model\RunStatus;
use Omnicrawl\RunnerInterface;

/**
 * A provider that answers from pages given, and keeps every call it gets
 * ($calls: "fetch <url>", "start <url> <url>...", "run <id>",
 * "results <id>"): the guardrails proved with nothing behind them - a
 * refused target must leave $calls empty. A run holds the pages asked, and
 * any $extra pages given (a service that would bring back more than it was
 * asked).
 */
final class FixedFetcher implements FetcherInterface, RunnerInterface
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<FetchRequest|CrawlRequest> every request as it came, its agent included */
    public array $requests = [];

    /** @var array<string, list<string>> */
    private array $runs = [];

    /**
     * @param array<string, array{0: int, 1: string, 2?: array<string, list<string>>, 3?: string}> $pages  by address: status, body, headers, the final address
     * @param list<string>                                                                              $extra addresses a run brings back besides those asked
     */
    public function __construct(
        private readonly array $pages = [],
        private readonly Capabilities $capabilities = new Capabilities(pageFetch: true, crawlRuns: true, rendering: true, headers: true),
        private readonly string $name = 'fixed',
        private readonly array $extra = [],
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTitle(): string
    {
        return 'Fixed pages';
    }

    public function capabilities(): Capabilities
    {
        return $this->capabilities;
    }

    public function fetch(FetchRequest $request): Fetched
    {
        $this->calls[] = 'fetch '.$request->url;
        $this->requests[] = $request;

        return $this->page($request->url, $request->render);
    }

    public function start(CrawlRequest $request): CrawlRun
    {
        $this->calls[] = 'start '.implode(' ', $request->urls);
        $this->requests[] = $request;
        $id = 'run-'.(\count($this->runs) + 1);
        $this->runs[$id] = [...$request->urls, ...$this->extra];

        return new CrawlRun($id, RunStatus::Running, $this->name, \count($request->urls), 0);
    }

    public function run(string $id): CrawlRun
    {
        $this->calls[] = 'run '.$id;

        return new CrawlRun($id, RunStatus::Succeeded, $this->name, \count($this->runs[$id] ?? []), \count($this->runs[$id] ?? []));
    }

    public function results(string $id, ?string $cursor = null): FetchedPage
    {
        $this->calls[] = 'results '.$id;

        return new FetchedPage(array_map(fn (string $url) => $this->page($url, false), $this->runs[$id] ?? []));
    }

    private function page(string $url, bool $render): Fetched
    {
        $page = $this->pages[$url] ?? [404, '<html><head><title>Not found</title></head></html>', ['content-type' => ['text/html']]];

        return new Fetched($url, $page[3] ?? $url, $page[0], $page[1], $page[2] ?? ['content-type' => ['text/html']], $render);
    }
}
