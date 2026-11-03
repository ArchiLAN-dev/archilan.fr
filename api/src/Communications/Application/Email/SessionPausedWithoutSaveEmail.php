<?php

declare(strict_types=1);

namespace App\Communications\Application\Email;

final class SessionPausedWithoutSaveEmail extends ArchilanEmail
{
    public function __construct(
        private readonly string $adminEmail,
        private readonly string $sessionId,
        private readonly string $siteUrl = '',
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

    public function htmlBody(): string
    {
        $inner = self::heading('⚠ Sauvegarde échouée à la mise en pause')
            .self::paragraph('La session '.self::strong($this->sessionId).' a été mise en pause automatiquement par le watchdog d\'inactivité, mais la sauvegarde Archipelago a échoué ou n\'a pas pu être envoyée.')
            .self::paragraph('Aucune progression n\'a été conservée pour cette session. Vérifie les logs du runner pour plus de détails.')
            .self::note('Alerte automatique ArchiLAN.');

        return $this->htmlLayout($inner, "Session {$this->sessionId} : sauvegarde échouée à la mise en pause.", self::logoUrlFor($this->siteUrl));
    }
}
