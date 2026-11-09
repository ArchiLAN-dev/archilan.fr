<?php

declare(strict_types=1);

namespace App\Communications\Application\Email;

/** Story 51.3: a survey of an event the member is concerned by opened. */
final class SurveyOpenedEmail extends ArchilanEmail
{
    public function __construct(
        private readonly string $recipientEmail,
        private readonly ?string $recipientDisplayName,
        private readonly string $surveyTitle,
        private readonly string $eventTitle,
        private readonly string $closesAt,
        private readonly bool $anonymous,
        private readonly string $answerUrl,
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
        return "Un sondage pour toi - {$this->eventTitle}";
    }

    public function textBody(): string
    {
        $name = $this->recipientDisplayName ?? $this->recipientEmail;
        $anonymous = $this->anonymous ? "\n\nTes réponses sont anonymes : on saura que tu as répondu, pas ce que tu as répondu." : '';

        return <<<TEXT
        Bonjour {$name},

        L'orga de "{$this->eventTitle}" a ouvert un sondage : "{$this->surveyTitle}". Il ne prend que quelques minutes.

        Le sondage est ouvert jusqu'au {$this->closesAt}.{$anonymous}

        Répondre : {$this->answerUrl}

        Merci !
        L'équipe ArchiLAN
        TEXT;
    }

    public function htmlBody(): string
    {
        $inner = self::heading("L'orga a une question pour toi")
            .self::greeting($this->recipientDisplayName ?? $this->recipientEmail)
            .self::paragraph("L'orga de ".self::strong($this->eventTitle).' a ouvert un sondage. Il ne prend que quelques minutes.')
            .self::infoBox([['Sondage', $this->surveyTitle], ["Ouvert jusqu'au", $this->closesAt]])
            .($this->anonymous ? self::paragraph('Tes réponses sont anonymes : on saura que tu as répondu, pas ce que tu as répondu.') : '')
            .self::button($this->answerUrl, 'Répondre')
            .self::note('Merci !')
            .self::signature();

        return $this->htmlLayout($inner, "Sondage ouvert jusqu'au {$this->closesAt} : {$this->surveyTitle}.", self::logoUrlFor($this->siteUrl));
    }
}
