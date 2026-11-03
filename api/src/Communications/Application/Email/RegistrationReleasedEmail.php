<?php

declare(strict_types=1);

namespace App\Communications\Application\Email;

final class RegistrationReleasedEmail extends ArchilanEmail
{
    public function __construct(
        private readonly string $recipientEmail,
        private readonly ?string $recipientDisplayName,
        private readonly string $eventTitle,
        private readonly string $eventUrl,
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
        return "Ta place a été libérée - {$this->eventTitle}";
    }

    public function textBody(): string
    {
        $name = $this->recipientDisplayName ?? $this->recipientEmail;

        return <<<TEXT
        Bonjour {$name},

        Les inscriptions à l'événement "{$this->eventTitle}" sont fermées, et ton inscription n'avait pas été confirmée : ta place a été libérée.

        Si c'est une erreur, écris-nous sur le Discord ArchiLAN. L'événement : {$this->eventUrl}

        À bientôt,
        L'équipe ArchiLAN
        TEXT;
    }

    public function htmlBody(): string
    {
        $inner = self::heading('Ta place a été libérée')
            .self::greeting($this->recipientDisplayName ?? $this->recipientEmail)
            .self::paragraph('Les inscriptions à '.self::strong($this->eventTitle).' sont fermées, et ton inscription n\'avait pas été confirmée : ta place a été libérée.')
            .self::paragraph('Si c\'est une erreur, écris-nous sur le Discord ArchiLAN.')
            .self::button($this->eventUrl, 'Voir l\'événement')
            .self::note('À bientôt,')
            .self::signature();

        return $this->htmlLayout($inner, "Ta place à {$this->eventTitle} a été libérée.", self::logoUrlFor($this->siteUrl));
    }
}
