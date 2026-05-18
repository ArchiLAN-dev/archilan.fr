<?php

declare(strict_types=1);

namespace App\Identity\Application;

interface DiscordResyncAllUsersInterface
{
    public function run(bool $dryRun = false): int;
}
