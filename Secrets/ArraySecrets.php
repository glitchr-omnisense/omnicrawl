<?php

namespace Omnicrawl\Secrets;

use Omnicrawl\Exception\InvalidConfigException;

/** Secrets given by the application (its vault's, a test's). */
final class ArraySecrets implements SecretsInterface
{
    /** @param array<string, string> $secrets by reference */
    public function __construct(private readonly array $secrets)
    {
    }

    public function get(string $reference): string
    {
        return $this->secrets[$reference] ?? throw new InvalidConfigException(\sprintf('No secret under "%s".', $reference));
    }
}
