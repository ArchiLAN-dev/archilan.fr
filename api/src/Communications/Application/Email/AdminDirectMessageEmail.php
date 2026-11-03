<?php

declare(strict_types=1);

namespace App\Communications\Application\Email;

final class AdminDirectMessageEmail extends ArchilanEmail
{
    public function __construct(
        private readonly string $recipientEmail,
        private readonly ?string $recipientDisplayName,
        private readonly string $messageSubject,
        private readonly string $messageBody,
        private readonly string $registrationUrl = '',
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
        return $this->messageSubject;
    }

    public function textBody(): string
    {
        return $this->messageBody."\n\n---\nCe message a été envoyé depuis le backoffice ArchiLAN.";
    }

    public function htmlBody(): string
    {
        $inner = self::heading($this->messageSubject)
            .self::textBlock($this->messageBody)
            .('' !== $this->registrationUrl ? self::button($this->registrationUrl, 'Voir mon inscription') : '')
            .self::note('Ce message t&#39;a été envoyé par l&#39;équipe ArchiLAN.');

        $preview = mb_substr(trim(preg_replace('/\s+/u', ' ', $this->messageBody) ?? ''), 0, 120);

        return $this->htmlLayout($inner, $preview, self::logoUrlFor($this->siteUrl));
    }
}
