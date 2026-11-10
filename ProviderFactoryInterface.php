<?php

namespace Omnicrawl;

/** Builds a provider from its options: its key's reference, the family's user_agent, its own settings. */
interface ProviderFactoryInterface extends \Omnisense\Contracts\Provider\ProviderFactoryInterface
{
    public function getName(): string;

    /** @param array<string, mixed> $options */
    public function create(array $options = []): ProviderInterface;
}
