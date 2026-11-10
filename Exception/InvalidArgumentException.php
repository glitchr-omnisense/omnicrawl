<?php

namespace Omnicrawl\Exception;

/** A call given what it cannot take: an address that is not http(s), a selector that does not parse. */
final class InvalidArgumentException extends \InvalidArgumentException implements OmnicrawlException
{
}
