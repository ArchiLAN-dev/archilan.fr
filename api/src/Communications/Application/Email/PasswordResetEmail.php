<?php

declare(strict_types=1);

namespace App\Communications\Application\Email;

use App\Communications\Application\ArchilanEmail;

final class PasswordResetEmail extends ArchilanEmail
{
    public function __construct(
        private readonly string $recipientEmail,
        private readonly ?string $recipientDisplayName,
        private readonly string $resetUrl,
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
        return 'Réinitialisation de ton mot de passe ArchiLAN';
    }

    public function textBody(): string
    {
        $name = $this->recipientDisplayName ?? $this->recipientEmail;

        return <<<TEXT
        Bonjour {$name},

        Tu as demandé à réinitialiser ton mot de passe ArchiLAN.

        Clique sur le lien ci-dessous pour choisir un nouveau mot de passe :
        {$this->resetUrl}

        Ce lien est valable 15 minutes. Si tu n'as pas fait cette demande, ignore ce message.

        L'équipe ArchiLAN
        TEXT;
    }
}
