<?php

declare(strict_types=1);

namespace App\Communications\Application\Email;

use App\Communications\Application\ArchilanEmail;

final class RegistrationConfirmationEmail extends ArchilanEmail
{
    /**
     * @param list<string> $selectedGameNames
     */
    public function __construct(
        private readonly string $recipientEmail,
        private readonly ?string $recipientDisplayName,
        private readonly string $eventTitle,
        private readonly string $eventStartsAt,
        private readonly string $eventVenue,
        private readonly array $selectedGameNames,
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
        return "Confirmation d'inscription - {$this->eventTitle}";
    }

    public function textBody(): string
    {
        $name = $this->recipientDisplayName ?? $this->recipientEmail;
        $gameList = [] === $this->selectedGameNames
            ? 'Aucun jeu sélectionné.'
            : implode("\n", array_map(
                static fn (string $n): string => '  - '.$n,
                $this->selectedGameNames,
            ));

        return <<<TEXT
        Bonjour {$name},

        Ton inscription à l'événement "{$this->eventTitle}" a bien été confirmée !

        📅 Date : {$this->eventStartsAt}
        📍 Lieu : {$this->eventVenue}

        🎮 Jeux sélectionnés :
        {$gameList}

        Retrouve tous les détails sur le site ArchiLAN.

        À très bientôt !
        L'équipe ArchiLAN
        TEXT;
    }
}
