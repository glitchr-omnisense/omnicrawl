<?php

namespace Omnicrawl\Exception;

/** The crawling service, or the target through the direct provider, could not be reached: a network error, a timeout. */
final class UnreachableException extends \RuntimeException implements OmnicrawlException
{
}
