<?php

namespace Omnicrawl\Exception;

/**
 * The target reserves its rights over what was asked: text and data mining
 * reserved (TDMRep: /.well-known/tdmrep.json, a tdm-reservation header or
 * meta) for an extraction or a crawl run under an authority that is no
 * licence, or a redirect to an address the family refuses.
 */
final class ReservedException extends RefusedException
{
}
