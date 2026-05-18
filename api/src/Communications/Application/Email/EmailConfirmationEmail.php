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

    public function htmlBody(): string
    {
        $name = htmlspecialchars(
            $this->recipientDisplayName ?? $this->recipientEmail,
            \ENT_QUOTES | \ENT_SUBSTITUTE,
            'UTF-8',
        );
        $url = htmlspecialchars($this->confirmationUrl, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        $inner = <<<HTML
        <!-- Greeting -->
        <p style="margin:0 0 20px;font-family:Inter,Arial,sans-serif;font-size:22px;font-weight:700;color:#e8edf4;line-height:1.3;">
          Bienvenue sur ArchiLAN&nbsp;! 🎮
        </p>

        <p style="margin:0 0 16px;font-family:Inter,Arial,sans-serif;font-size:15px;color:#e8edf4;line-height:1.65;">
          Bonjour <strong style="color:#9580f5;">{$name}</strong>,
        </p>

        <p style="margin:0 0 16px;font-family:Inter,Arial,sans-serif;font-size:15px;color:#b0bec8;line-height:1.65;">
          Ton compte ArchiLAN a bien été créé. Pour l'activer et pouvoir t'inscrire à nos événements LAN, confirme ton adresse email en cliquant sur le bouton ci-dessous.
        </p>

        <!-- Divider -->
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
                ✉&nbsp;&nbsp;Confirmer mon adresse email
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
          ⏱ Ce lien est valable <strong style="color:#9580f5;">24 heures</strong>.
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

        return $this->htmlLayout($inner, 'Confirme ton adresse email pour activer ton compte ArchiLAN.', $logoUrl);
    }
}
