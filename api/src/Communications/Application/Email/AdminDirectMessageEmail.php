<?php

declare(strict_types=1);

namespace App\Communications\Application\Email;

use App\Communications\Application\ArchilanEmail;

final class AdminDirectMessageEmail extends ArchilanEmail
{
    public function __construct(
        private readonly string $recipientEmail,
        private readonly ?string $recipientDisplayName,
        private readonly string $messageSubject,
        private readonly string $messageBody,
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
}
