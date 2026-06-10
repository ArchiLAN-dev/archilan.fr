<?php

declare(strict_types=1);

namespace App\GameSelection\Application;

interface GameUsageCounterInterface
{
    /**
     * How many times a game is actually used: the number of session slots plus weekly templates
     * that reference it. Used both for the admin list display and the delete guard.
     */
    public function count(string $gameId): int;
}
