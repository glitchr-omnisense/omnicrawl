<?php

namespace Omnicrawl\Testing;

use Omnicrawl\Registry;

/**
 * The check every package of the family runs: each of its classes loaded in
 * a PHP process of its own, with nothing from outside but
 * omnisense/omnicrawl, omnisense/contracts and what the package requires -
 * no framework, no HTTP client, no application. omnisense/contracts'
 * BareTestCase, with omnicrawl's core among the folders that may load and
 * its harness's OMNICRAWL_AUTOLOAD.
 *
 * @suggested phpunit/phpunit (test support: loaded by a package's tests only)
 */
abstract class BareTestCase extends \Omnisense\Contracts\Testing\BareTestCase
{
    /** @var non-empty-string */
    protected const AUTOLOAD_ENV = 'OMNICRAWL_AUTOLOAD';

    protected static function coreDirs(): array
    {
        return [...parent::coreDirs(), self::dirOf(Registry::class)];
    }
}
