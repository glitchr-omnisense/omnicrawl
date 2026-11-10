<?php

namespace Omnicrawl;

use Omnicrawl\Exception\InvalidConfigException;

/**
 * A provider's configuration while its factory builds it: omnisense/
 * contracts' Config, with omnicrawl's prefix ("omnicrawl.factory_name",
 * "omnicrawl.backoff"...) and its InvalidConfigException. Every provider
 * takes "user_agent" (required: the family's identity, Agent's shape; the
 * registry gives it the gate's), "policy" (the site's tightening of the
 * provider's usage policy) and "usage" (what the site says of itself).
 */
final class Config extends \Omnisense\Contracts\Provider\Config
{
    /** @var string */
    public const PREFIX = 'omnicrawl';

    /** The family's User-Agent, checked: Agent::parse() of the "user_agent" option. */
    public function agent(): Agent
    {
        return Agent::parse($this->string('user_agent') ?? throw $this->invalid(\sprintf('The "%s" provider needs a user_agent: a product token and version with a contact, such as "ExampleBot/1.0 (+https://example.org/bot; bot@example.org)".', $this->string($this->key('factory_name')) ?? '?')));
    }

    protected function invalid(string $message): \Throwable
    {
        return new InvalidConfigException($message);
    }
}
