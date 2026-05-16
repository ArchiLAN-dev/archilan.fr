<?php

declare(strict_types=1);

namespace App\Communications\Application\Email;

use App\Communications\Application\ArchilanEmail;

final class SessionPausedWithoutSaveEmail extends ArchilanEmail
{
    public function __construct(
        private readonly string $adminEmail,
        private readonly string $sessionId,
    ) {
    }

    public function to(): string
    {
        return $this->adminEmail;
    }

    public function toName(): string
    {
        return 'Admin ArchiLAN';
    }

    public function subject(): string
    {
        return "[ArchiLAN] Session {$this->sessionId} - sauvegarde échouée à la mise en pause";
    }

    public function textBody(): string
    {
        return <<<TEXT
        [ArchiLAN - alerte automatique]

        La session {$this->sessionId} a été mise en pause automatiquement par le watchdog d'inactivité, mais la sauvegarde Archipelago a échoué ou n'a pas pu être uploadée.

        Aucune progression n'a été conservée pour cette session.

        Veuillez vérifier les logs du runner pour plus de détails.
        TEXT;
    }
}
