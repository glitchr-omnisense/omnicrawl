<?php

namespace Omnicrawl;

use Omnicrawl\Exception\InvalidConfigException;
use Omnicrawl\Exception\UsageLimitReachedException;
use Omnicrawl\Policy\Plans;
use Omnisense\Contracts\Provider\Config as BaseConfig;
use Omnisense\Contracts\Provider\ProviderFactory as BaseFactory;

/**
 * A provider's factory fills a Config - its name, title, required options
 * and defaults - then builds the provider from it. Every omnicrawl factory
 * requires "user_agent", checked as Agent::parse() checks it (a product
 * token, a version, a contact; no browser disguise) before the provider is
 * built: a provider cannot exist without the family's identity. A
 * provider that publishes limits per plan declares them (plans(): its
 * policy.json's "plans"); the "plan" option picks one, an unknown plan - or any plan,
 * for a provider that declares none - is refused before the provider is
 * built, and the provider reads its policy under it.
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

    /** The plans its provider's policy declares; null when it publishes no limits per plan. */
    protected function plans(): ?Plans
    {
        return null;
    }

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
        $plan = $c->string('plan');
        if (null !== $plan) {
            ($this->plans() ?? throw new InvalidConfigException(\sprintf('The "%s" provider declares no plans: no "plan" option.', $c->string($c->key('factory_name')) ?? '?')))->check($plan);
        }

        return $this->build($c, $c->agent());
    }

    private static function own(BaseConfig $c): Config
    {
        return $c instanceof Config ? $c : throw new InvalidConfigException('An omnicrawl factory builds from omnicrawl\'s Config.');
    }
}
