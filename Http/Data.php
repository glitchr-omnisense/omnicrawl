<?php

namespace Omnicrawl\Http;

/**
 * A decoded JSON answer, read by key without trusting its shape: each
 * reader gives its type or null (an empty list), whatever the service
 * sent. A key may be a path: "data.metadata.statusCode".
 */
final readonly class Data extends \Omnisense\Contracts\Http\Data
{
}
