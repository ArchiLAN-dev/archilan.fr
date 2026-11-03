<?php

declare(strict_types=1);

namespace App\Communications\Application\Email;

final class EventCapacityReachedEmail extends ArchilanEmail
{
    public function __construct(
        private readonly string $adminEmail,
        private readonly ?string $adminDisplayName,
        private readonly string $eventTitle,
        private readonly int $capacity,
        private readonly string $registrationsUrl = '',
        private readonly string $siteUrl = '',
    ) {
    }

    public function to(): string
    {
        return $this->adminEmail;
    }

    public function toName(): ?string
    {
        return $this->adminDisplayName;
    }

    public function subject(): string
    {
        return sprintf('[ArchiLAN] Événement complet : %s', $this->eventTitle);
    }

    public function textBody(): string
    {
        return <<<TEXT
        Bonjour,

        L'événement "{$this->eventTitle}" a atteint sa capacité maximale ({$this->capacity} places).

        Connecte-toi au backoffice pour gérer les inscriptions.

        L'équipe ArchiLAN
        TEXT;
    }

    public function htmlBody(): string
    {
        $inner = self::heading('Événement complet')
            .self::paragraph(self::strong($this->eventTitle).' a atteint sa capacité maximale ('.$this->capacity.' places).')
            .('' !== $this->registrationsUrl
                ? self::button($this->registrationsUrl, 'Voir les inscriptions')
                : self::paragraph('Connecte-toi au backoffice pour gérer les inscriptions.'))
            .self::signature();

        return $this->htmlLayout($inner, "{$this->eventTitle} est complet ({$this->capacity} places).", self::logoUrlFor($this->siteUrl));
    }
}
