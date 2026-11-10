<?php

namespace Omnicrawl\Exception;

/**
 * The family refuses to collect this address, and no request was made for
 * it to the crawling service: the target is declared as refused, the
 * operation is not one its declaration allows, its robots.txt disallows it,
 * it reserves its text and data mining rights, or the page itself says
 * not to keep it. $url is the address refused, $reason one of the REASON_*
 * constants, $reference where the rule was read (a declaration's reference,
 * the robots.txt address).
 */
abstract class RefusedException extends \RuntimeException implements OmnicrawlException
{
    public const REASON_DECLARED = 'declared-refused';
    public const REASON_OPERATION = 'operation-not-allowed';
    public const REASON_RENDERING = 'rendering-not-allowed';
    public const REASON_PROVIDER = 'provider-not-allowed';
    public const REASON_ROBOTS = 'robots-disallowed';
    public const REASON_ROBOTS_UNREACHABLE = 'robots-unreachable';
    public const REASON_TDM = 'tdm-reserved';
    public const REASON_REDIRECT = 'redirect-refused';

    final public function __construct(
        string $message,
        public readonly string $url,
        public readonly string $reason,
        public readonly ?string $reference = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
