<?php

namespace Omnicrawl\Exception;

/** A provider, a target declaration or a User-Agent given in a shape the family cannot work with. */
final class InvalidConfigException extends \InvalidArgumentException implements OmnicrawlException
{
}
