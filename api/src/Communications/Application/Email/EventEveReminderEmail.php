<?php

declare(strict_types=1);

namespace App\Communications\Application\Email;

/**
 * Story 53.8: the day before the event, the start, the venue and what is left to do. Never the practical info: it can
 * hold a wifi or a door code, which has no place in a mail.
 */
final class EventEveReminderEmail extends ArchilanEmail
{
    /**
     * @param list<string> $stepsLeft empty when the registrant is ready
     */
    public function __construct(
        private readonly string $recipientEmail,
        private readonly ?string $recipientDisplayName,
        private readonly string $eventTitle,
        private readonly string $startsAt,
        private readonly string $venue,
        private readonly array $stepsLeft,
        private readonly string $hubUrl,
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
        return "C'est demain ! - {$this->eventTitle}";
    }

    public function textBody(): string
    {
        $name = $this->recipientDisplayName ?? $this->recipientEmail;
        $venue = '' === $this->venue ? '' : "\nLieu : {$this->venue}";
        $steps = [] === $this->stepsLeft
            ? 'Tout est prêt de ton côté, il ne reste plus qu\'à venir !'
            : "Il te reste à faire :\n".implode("\n", array_map(static fn (string $step): string => '- '.$step, $this->stepsLeft));

        return <<<TEXT
        Bonjour {$name},

        C'est demain ! "{$this->eventTitle}" commence le {$this->startsAt}.{$venue}

        {$steps}

        Ton espace pour l'événement, avec les infos pratiques et tes outils : {$this->hubUrl}

        À demain !
        L'équipe ArchiLAN
        TEXT;
    }

    public function htmlBody(): string
    {
        $rows = [['Début', $this->startsAt]];
        if ('' !== $this->venue) {
            $rows[] = ['Lieu', $this->venue];
        }

        $inner = self::heading('C\'est demain !')
            .self::greeting($this->recipientDisplayName ?? $this->recipientEmail)
            .self::paragraph(self::strong($this->eventTitle).' commence demain. On a hâte de te voir !')
            .self::infoBox($rows)
            .([] === $this->stepsLeft
                ? self::paragraph('Tout est prêt de ton côté, il ne reste plus qu\'à venir !')
                : self::bulletList('Il te reste à faire', $this->stepsLeft))
            .self::paragraph('Les infos pratiques et tes outils pour l\'événement t\'attendent dans ton espace.')
            .self::button($this->hubUrl, 'Mon espace')
            .self::note('À demain !')
            .self::signature();

        return $this->htmlLayout($inner, "{$this->eventTitle} commence le {$this->startsAt}.", self::logoUrlFor($this->siteUrl));
    }
}
