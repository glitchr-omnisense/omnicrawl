<?php

namespace Omnicrawl\Guard;

use Omnicrawl\Model\Operation;
use Omnicrawl\Robots\RobotsTxt;
use Omnicrawl\Target\Target;
use Omnicrawl\Tdm\Reservation;
use Omnisense\Contracts\Policy\PolicyGuard;

/**
 * An address the gate let through, and why: its normalised form, its
 * origin and host, the target that decided (a declaration or the default),
 * robots.txt as it was read, what tdmrep.json says of it, the host's
 * guard (its limits, Crawl-delay included).
 */
final readonly class Admission
{
    public function __construct(
        public string $url,
        public string $origin,
        public string $host,
        public Operation $operation,
        public Target $target,
        public RobotsTxt $robots,
        public Reservation $tdm,
        public PolicyGuard $hostGuard,
        public bool $render = false,
    ) {
    }
}
