<?php

namespace Omnicrawl\Exception;

/**
 * The target's declaration refuses it: the site is declared as refused
 * (its terms forbid automated collection), the operation or JavaScript
 * rendering is not one it allows, or the provider is one the declaration's
 * authority does not cover (a service that evades blocks by itself).
 */
final class TargetRefusedException extends RefusedException
{
}
