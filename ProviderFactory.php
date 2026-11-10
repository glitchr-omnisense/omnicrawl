<?php

namespace Omnicrawl;

use Omnicrawl\Exception\InvalidConfigException;
use Omnicrawl\Exception\UsageLimitReachedException;
use Omnisense\Contracts\Provider\Config as BaseConfig;
use Omnisense\Contracts\Provider\ProviderFactory as BaseFactory;

/**
 * A provider's factory fills a Config - its name, title, required options
 * and defaults - then builds the provider from it. Every omnicrawl factory
 * requires "user_agent", checked as Agent::parse() checks it (a product
 * token, a version, a contact; no browser disguise) before the provider is
 * built: a provider cannot exist without the family's identity.
 * omnisense/contracts' factory underneath gives the Config the provider's
 * Backoff and, once built, its PolicyGuard.
 */
abstract class ProviderFactory extends BaseFactory implements ProviderFactoryInterface
{
    public function create(array $options = []): ProviderInterface
    {
        $provider = parent::create($options);

        return $provider instanceof ProviderInterface ? $provider : throw new InvalidConfigException(\sprintf('The "%s" factory built no omnicrawl provider.', $this->getName()));
    }

    /** @param array<string, mixed> $options */
    public function createConfig(array $options = []): Config
    {
        return self::own(parent::createConfig($options));
    }

    /** The factory's name and title, its required options, the defaults of the others. */
    abstract protected function populate(Config $c): void;

    /** The provider, from a Config that holds everything it needs; $agent is the family's User-Agent, checked. */
    abstract protected function build(Config $c, Agent $agent): ProviderInterface;

    protected function newConfig(array $options): Config
    {
        return new Config($options);
    }

    protected function limitException(): string
    {
        return UsageLimitReachedException::class;
    }

    final protected function configure(BaseConfig $c): void
    {
        $this->populate(self::own($c));
    }

    final protected function make(BaseConfig $c): ProviderInterface
    {
        $c = self::own($c);

        return $this->build($c, $c->agent());
    }

    private static function own(BaseConfig $c): Config
    {
        return $c instanceof Config ? $c : throw new InvalidConfigException('An omnicrawl factory builds from omnicrawl\'s Config.');
    }
}
