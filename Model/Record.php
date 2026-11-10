<?php

namespace Omnicrawl\Model;

use Omnisense\Contracts\Policy\Permission;
use Omnisense\Contracts\Policy\Rights;

/**
 * A page as the family hands it over, only ever made by the gate: what
 * the target served (its status, headers when the provider reports them,
 * its body), its provenance, its signals, and the rights that follow from
 * the target's declaration and the page's own signals (noarchive forbids
 * storing it). Unknown rights are not permission: mayStore() and
 * mayRepublish() say yes only to what a declaration allowed.
 */
final readonly class Record
{
    /** @param ?array<string, list<string>> $headers */
    public function __construct(
        public string $url,
        public string $finalUrl,
        public int $status,
        public string $body,
        public ?array $headers,
        public bool $rendered,
        public bool $html,
        public Provenance $provenance,
        public Signals $signals,
        public Rights $rights,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    /** Whether its declaration allows keeping it, and the page does not forbid it. */
    public function mayStore(): bool
    {
        return Permission::Allowed === $this->rights->storage;
    }

    /** Whether its declaration allows publishing it again elsewhere. */
    public function mayRepublish(): bool
    {
        return Permission::Allowed === $this->rights->redistribution;
    }

    /** @return array<string, mixed> everything but the body */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'finalUrl' => $this->finalUrl,
            'status' => $this->status,
            'rendered' => $this->rendered,
            'html' => $this->html,
            'bytes' => \strlen($this->body),
            'provenance' => $this->provenance->toArray(),
            'signals' => $this->signals->toArray(),
            'rights' => $this->rights->toArray(),
        ];
    }
}
