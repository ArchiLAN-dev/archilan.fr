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
        private readonly string $siteUrl = '',
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

    public function htmlBody(): string
    {
        $inner = self::heading('Ton inscription n\'est pas encore confirmée')
            .self::greeting($this->recipientDisplayName ?? $this->recipientEmail)
            .self::paragraph('Ta place à '.self::strong($this->eventTitle).' est réservée, mais ton inscription n\'est pas encore confirmée.')
            .self::infoBox([['Clôture des inscriptions', $this->registrationClosesAt]])
            .self::paragraph('Sans confirmation d\'ici là, ta place sera libérée pour un autre joueur.')
            .self::button($this->recapUrl, 'Confirmer mon inscription')
            .self::note('À très bientôt !')
            .self::signature();

        return $this->htmlLayout($inner, "Confirme ton inscription à {$this->eventTitle} avant le {$this->registrationClosesAt}.", self::logoUrlFor($this->siteUrl));
    }
}
