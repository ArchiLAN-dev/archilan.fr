<?php

declare(strict_types=1);

namespace App\Communications\Application\Email;

use App\Communications\Application\ArchilanEmail;

final class EventCapacityReachedEmail extends ArchilanEmail
{
    public function __construct(
        private readonly string $adminEmail,
        private readonly ?string $adminDisplayName,
        private readonly string $eventTitle,
        private readonly int $capacity,
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
}
