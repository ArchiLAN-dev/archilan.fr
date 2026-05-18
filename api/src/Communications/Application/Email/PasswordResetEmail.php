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

    public function htmlBody(): string
    {
        $name = htmlspecialchars(
            $this->recipientDisplayName ?? $this->recipientEmail,
            \ENT_QUOTES | \ENT_SUBSTITUTE,
            'UTF-8',
        );
        $url = htmlspecialchars($this->resetUrl, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        $inner = <<<HTML
        <!-- Heading -->
        <p style="margin:0 0 20px;font-family:Inter,Arial,sans-serif;font-size:22px;font-weight:700;color:#e8edf4;line-height:1.3;">
          Réinitialisation de mot de passe 🔑
        </p>

        <p style="margin:0 0 16px;font-family:Inter,Arial,sans-serif;font-size:15px;color:#e8edf4;line-height:1.65;">
          Bonjour <strong style="color:#9580f5;">{$name}</strong>,
        </p>

        <p style="margin:0 0 16px;font-family:Inter,Arial,sans-serif;font-size:15px;color:#b0bec8;line-height:1.65;">
          Une demande de réinitialisation de mot de passe a été effectuée pour ton compte ArchiLAN. Clique sur le bouton ci-dessous pour choisir un nouveau mot de passe.
        </p>

        <!-- Warning box -->
        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
          <tr>
            <td style="background-color:#1a1840;border-left:3px solid #e89420;border-radius:0 6px 6px 0;padding:12px 16px;margin-bottom:24px;">
              <p style="margin:0;font-family:Inter,Arial,sans-serif;font-size:13px;color:#e89420;line-height:1.5;">
                ⚠️&nbsp; Si tu n'es pas à l'origine de cette demande, ignore ce message. Ton mot de passe restera inchangé.
              </p>
            </td>
          </tr>
        </table>

        <!-- Spacer -->
        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
          <tr><td height="8" style="font-size:0;line-height:0;">&nbsp;</td></tr>
        </table>

        <!-- CTA button -->
        <table role="presentation" cellspacing="0" cellpadding="0" border="0">
          <tr>
            <td style="border-radius:8px;background-color:#231470;">
              <a href="{$url}"
                 target="_blank"
                 style="display:inline-block;padding:14px 32px;font-family:Inter,Arial,sans-serif;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:8px;letter-spacing:0.2px;mso-padding-alt:14px 32px;">
                🔒&nbsp;&nbsp;Choisir un nouveau mot de passe
              </a>
            </td>
          </tr>
        </table>

        <!-- Spacer -->
        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
          <tr><td height="28" style="font-size:0;line-height:0;">&nbsp;</td></tr>
        </table>

        <!-- Expiry note -->
        <p style="margin:0 0 20px;font-family:Inter,Arial,sans-serif;font-size:13px;color:#7a8ba8;line-height:1.6;">
          ⏱ Ce lien est valable <strong style="color:#9580f5;">15 minutes</strong> et ne peut être utilisé qu'une seule fois.
        </p>

        <!-- Divider -->
        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
          <tr>
            <td height="1" style="background-color:#281f52;font-size:0;line-height:0;">&nbsp;</td>
          </tr>
        </table>

        <!-- Spacer -->
        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
          <tr><td height="20" style="font-size:0;line-height:0;">&nbsp;</td></tr>
        </table>

        <!-- Fallback link -->
        <p style="margin:0;font-family:Inter,Arial,sans-serif;font-size:12px;color:#4a5568;line-height:1.6;">
          Le bouton ne fonctionne pas ? Copie ce lien dans ton navigateur :<br>
          <a href="{$url}" style="color:#7a8ba8;word-break:break-all;">{$url}</a>
        </p>
        HTML;

        $logoUrl = '' !== $this->siteUrl ? rtrim($this->siteUrl, '/').'/images/logo.webp' : '';

        return $this->htmlLayout($inner, 'Réinitialise ton mot de passe ArchiLAN - lien valable 15 minutes.', $logoUrl);
    }
}
