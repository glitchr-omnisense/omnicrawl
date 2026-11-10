<?php

namespace Omnicrawl;

use Omnicrawl\Exception\InvalidConfigException;

/**
 * The family's identity on every request a target sees: a product token
 * and version that name the site doing the collecting, a contact (an
 * address where the site explains its bot, an e-mail address), and the
 * family's own token:
 *
 *   ExampleBot/1.0 (+https://example.org/bot; bot@example.org) omnicrawl/1
 *
 * The product token is what robots.txt groups are matched against (RFC
 * 9309 §2.2.1: letters, "_" and "-" only), and so is "omnicrawl": a site
 * can address this site's bot or the whole family. Required by every
 * factory, given to every provider, sent by the gate when it reads
 * robots.txt; there is no anonymous mode and no browser disguise.
 */
final readonly class Agent
{
    /** The family's own product token, appended to every User-Agent and matched in robots.txt. */
    public const FAMILY_TOKEN = 'omnicrawl';

    public const FAMILY_VERSION = '1';

    private function __construct(
        public string $token,
        public string $version,
        public string $contact,
        private string $given,
    ) {
    }

    /**
     * A User-Agent as a site writes it: "<Token>/<version> (<contact>...)",
     * the contact an http(s) address or an e-mail address.
     *
     * @throws InvalidConfigException when it names no product token, no version or no contact, or poses as a browser
     */
    public static function parse(string $userAgent): self
    {
        $userAgent = trim($userAgent);
        if (1 !== preg_match('~^([A-Za-z_-]+)/(\S+)~', $userAgent, $m)) {
            throw new InvalidConfigException(\sprintf('The User-Agent "%s" must start with a product token (letters, "_", "-") and its version: "ExampleBot/1.0 (+https://example.org/bot; bot@example.org)".', $userAgent));
        }
        if (1 !== preg_match('~(https?://[^\s;)]+|[^\s;()<>@]+@[^\s;()<>@]+\.[a-z]{2,})~i', $userAgent, $contact)) {
            throw new InvalidConfigException(\sprintf('The User-Agent "%s" must carry a contact: an http(s) address that explains the bot, or an e-mail address.', $userAgent));
        }
        if (1 === preg_match('~^Mozilla/|\b(?:Chrome|Safari|Firefox|Edg|Gecko)/~', $userAgent)) {
            throw new InvalidConfigException(\sprintf('The User-Agent "%s" poses as a browser: the family identifies itself.', $userAgent));
        }
        if (0 === strcasecmp($m[1], self::FAMILY_TOKEN)) {
            throw new InvalidConfigException('The User-Agent must name the site\'s own bot first; "omnicrawl" is appended to it.');
        }

        return new self($m[1], $m[2], $contact[1], $userAgent);
    }

    /** The User-Agent header sent: the one given, the family's token appended. */
    public function header(): string
    {
        return 1 === preg_match('~\b'.self::FAMILY_TOKEN.'/~i', $this->given) ? $this->given : $this->given.' '.self::FAMILY_TOKEN.'/'.self::FAMILY_VERSION;
    }

    /** @return list<string> the product tokens robots.txt groups are matched against: the site's bot, then the family */
    public function tokens(): array
    {
        return [$this->token, self::FAMILY_TOKEN];
    }

    public function __toString(): string
    {
        return $this->header();
    }
}
