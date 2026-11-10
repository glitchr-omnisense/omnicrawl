<?php

namespace Omnicrawl\Model;

/** A run as the service says it stands: its id, its status, the pages it holds of those asked. */
final readonly class CrawlRun
{
    public function __construct(
        public string $id,
        public RunStatus $status,
        public string $provider,
        public ?int $total = null,
        public ?int $completed = null,
    ) {
    }

    /** @return array{id: string, status: string, provider: string, total: ?int, completed: ?int} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'status' => $this->status->value, 'provider' => $this->provider, 'total' => $this->total, 'completed' => $this->completed];
    }
}
