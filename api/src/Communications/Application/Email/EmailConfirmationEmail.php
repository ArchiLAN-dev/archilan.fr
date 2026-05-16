<?php

declare(strict_types=1);

namespace App\Communications\Application\Email;

use App\Communications\Application\ArchilanEmail;

final class EmailConfirmationEmail extends ArchilanEmail
{
    public function __construct(
        private readonly string $recipientEmail,
        private readonly ?string $recipientDisplayName,
        private readonly string $confirmationUrl,
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
        return 'Confirme ton adresse email ArchiLAN';
    }

    public function textBody(): string
    {
        $name = $this->recipientDisplayName ?? $this->recipientEmail;

        return <<<TEXT
        Bonjour {$name},

        Bienvenue sur ArchiLAN ! Pour activer ton compte et t'inscrire aux événements, confirme ton adresse email en cliquant sur le lien ci-dessous :
        {$this->confirmationUrl}

        Ce lien est valable 24 heures.

        Si tu n'as pas créé de compte ArchiLAN, ignore ce message.

        L'équipe ArchiLAN
        TEXT;
    }
}
