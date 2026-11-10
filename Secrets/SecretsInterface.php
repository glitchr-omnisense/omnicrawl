<?php

namespace Omnicrawl\Secrets;

/**
 * Where a provider's key is read, by reference: a provider's options name
 * the key ("FIRECRAWL_API_KEY"), never hold it, so that a configuration
 * file, a dump or an error carries no secret.
 */
interface SecretsInterface
{
    /** @throws \Omnicrawl\Exception\InvalidConfigException when nothing is kept under the reference */
    public function get(string $reference): string;
}
