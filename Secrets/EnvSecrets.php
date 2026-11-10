<?php

namespace Omnicrawl\Secrets;

use Omnicrawl\Exception\InvalidConfigException;

/** Secrets read from the environment: getenv(), then $_ENV and $_SERVER (what a dotenv loader fills). */
final class EnvSecrets implements SecretsInterface
{
    public function get(string $reference): string
    {
        $value = getenv($reference);
        if (!\is_string($value) || '' === $value) {
            $value = $_ENV[$reference] ?? $_SERVER[$reference] ?? null;
        }

        return \is_string($value) && '' !== $value ? $value : throw new InvalidConfigException(\sprintf('No secret in the environment under "%s".', $reference));
    }
}
