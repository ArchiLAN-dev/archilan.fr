<?php

declare(strict_types=1);

namespace App\Communications\Application\Email;

final class RegistrationConfirmationReminderEmail extends ArchilanEmail
{
    public function __construct(
        private readonly string $recipientEmail,
        private readonly ?string $recipientDisplayName,
        private readonly string $eventTitle,
        private readonly string $registrationClosesAt,
        private readonly string $recapUrl,
    ) {
    }

    public function to(): string
    {
        return $this->recipientEmail;
    }

    public function toName(): ?string
    {
        return $this->recipientDisplayName;
    }

    public function subject(): string
    {
        return "Confirme ton inscription - {$this->eventTitle}";
    }

    public function textBody(): string
    {
        $name = $this->recipientDisplayName ?? $this->recipientEmail;

        return <<<TEXT
        Bonjour {$name},

        Ta place à l'événement "{$this->eventTitle}" est réservée, mais ton inscription n'est pas encore confirmée.

        Les inscriptions ferment le {$this->registrationClosesAt}. Sans confirmation d'ici là, ta place sera libérée pour un autre joueur.

        Confirmer mon inscription : {$this->recapUrl}

        À très bientôt !
        L'équipe ArchiLAN
        TEXT;
    }
}
