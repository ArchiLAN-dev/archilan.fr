<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Message;

/**
 * Marqueur planifié (toutes les 2 min) : déclenche la réconciliation des runs bloquées dans un statut
 * transitoire (starting / stopping / restarting) avec l'état réel de leur session (story 17.14).
 */
final readonly class ReconcileStuckRunsMessage
{
}
