<?php

namespace Omnicrawl\Testing;

/**
 * An HTTP client that answers from what it was given and keeps every
 * request made ($requests): a provider's calls, and the gate's robots.txt
 * reads, checked with no network. omnisense/contracts' RecordedHttpClient,
 * under omnicrawl's name.
 */
final class RecordedHttpClient extends \Omnisense\Contracts\Testing\RecordedHttpClient
{
}
