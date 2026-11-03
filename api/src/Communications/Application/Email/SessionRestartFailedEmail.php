<?php

declare(strict_types=1);

namespace App\Communications\Application\Email;

final class SessionRestartFailedEmail extends ArchilanEmail
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
        return "[ArchiLAN] Session {$this->sessionId} - redémarrage échoué";
    }

    public function textBody(): string
    {
        return <<<TEXT
        [ArchiLAN - alerte automatique]

        La session {$this->sessionId} n'a pas pu redémarrer après une tentative de relance.

        La session a été remise en pause. Veuillez vérifier les logs du bridge et du runner avant de relancer la partie.
        TEXT;
    }

    public function htmlBody(): string
    {
        $inner = self::heading('⚠ Redémarrage de session échoué')
            .self::paragraph('La session '.self::strong($this->sessionId).' n\'a pas pu redémarrer après une tentative de relance.')
            .self::paragraph('Elle a été remise en pause. Vérifie les logs du bridge et du runner avant de relancer la partie.')
            .self::note('Alerte automatique ArchiLAN.');

        return $this->htmlLayout($inner, "Session {$this->sessionId} : redémarrage échoué.", self::logoUrlFor($this->siteUrl));
    }
}
