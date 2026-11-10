<?php

namespace Omnicrawl\Exception;

/**
 * The crawling service answered, and not with what was asked: an error
 * status, a body it could not read. The status is the exception's code (0
 * for a body that could not be read). Not the target's status: a target
 * that answers 404 is a record with status 404.
 */
class ProviderException extends \RuntimeException implements OmnicrawlException
{
    final public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public static function answered(string $provider, string $what, int|string $status, string $detail = ''): self
    {
        $message = rtrim(\sprintf('%s answered %s for %s. %s', $provider, (string) $status, $what, mb_substr(trim(strip_tags($detail)), 0, 300)));

        return new self($message, \is_int($status) && 200 !== $status ? $status : 0);
    }

    /** The HTTP status the service answered; 0 when it answered a body that could not be read. */
    public function status(): int
    {
        return $this->getCode();
    }
}
