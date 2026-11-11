<?php

declare(strict_types=1);

namespace App\Communications\Application\Email;

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
        private readonly string $registrationUrl = '',
        private readonly string $siteUrl = '',
        /** Story 53.7: the registrant hub of the event, the place to come back to. */
        private readonly string $hubUrl = '',
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

        {$this->detailsLine()}{$this->hubLine()}

        À très bientôt !
        L'équipe ArchiLAN
        TEXT;
    }

    public function htmlBody(): string
    {
        $inner = self::heading('Inscription confirmée 🎉')
            .self::greeting($this->recipientDisplayName ?? $this->recipientEmail)
            .self::paragraph('Ton inscription à '.self::strong($this->eventTitle).' est confirmée. On a hâte de te voir !')
            .self::infoBox([['📅 Date', $this->eventStartsAt], ['📍 Lieu', $this->eventVenue]])
            .self::bulletList('🎮 Tes jeux', $this->selectedGameNames, 'Aucun jeu sélectionné.')
            .('' !== $this->hubUrl ? self::paragraph("Ce qu'il te reste à faire, tes codes et les infos pratiques t'attendent dans ton espace pour l'événement.").self::button($this->hubUrl, "Mon espace pour l'événement") : '')
            .('' !== $this->registrationUrl ? self::button($this->registrationUrl, 'Voir mon inscription') : '')
            .self::note('À très bientôt !')
            .self::signature();

        return $this->htmlLayout($inner, "Ton inscription à {$this->eventTitle} est confirmée.", self::logoUrlFor($this->siteUrl));
    }

    private function hubLine(): string
    {
        return '' !== $this->hubUrl
            ? "
Ton espace pour l'événement (ce qu'il te reste à faire, infos pratiques) : {$this->hubUrl}"
            : '';
    }

    private function detailsLine(): string
    {
        return '' !== $this->registrationUrl
            ? "Retrouve ton inscription : {$this->registrationUrl}"
            : 'Retrouve tous les détails sur le site ArchiLAN.';
    }
}
