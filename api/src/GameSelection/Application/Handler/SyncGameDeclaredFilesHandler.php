<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Handler;

use App\GameSelection\Application\Command\SyncGameDeclaredFiles;
use App\GameSelection\Application\Message\SyncGameDeclaredFilesMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncGameDeclaredFilesHandler
{
    public function __construct(private SyncGameDeclaredFiles $sync)
    {
    }

    public function __invoke(SyncGameDeclaredFilesMessage $message): void
    {
        $this->sync->syncAll();
    }
}
