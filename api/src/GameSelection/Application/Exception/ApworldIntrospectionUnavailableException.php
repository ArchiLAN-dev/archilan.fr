<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Exception;

/**
 * The orchestrator gave no option types or no location names for a candidate apworld (story 38.6
 * review). Every Archipelago world has the common options and at least one location, so an empty
 * answer is an orchestrator that did not answer: promoting on it would wipe the game's tables.
 */
final class ApworldIntrospectionUnavailableException extends \RuntimeException
{
}
