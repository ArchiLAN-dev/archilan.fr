<?php

declare(strict_types=1);

namespace App\Communications\Application\Email;

use App\Communications\Application\ArchilanEmail;

final class MembershipExpiredEmail extends ArchilanEmail
{
    public function __construct(
        private readonly string $recipientEmail,
        private readonly ?string $recipientDisplayName,
        private readonly string $renewalUrl,
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
        return 'Votre adhésion ArchiLAN a expiré';
    }

    public function textBody(): string
    {
        $name = $this->recipientDisplayName ?? $this->recipientEmail;

        return <<<TEXT
        Bonjour {$name},

        Ton adhésion ArchiLAN a expiré. Ton accès aux avantages membres a été retiré.

        Pour continuer à participer à nos événements LAN, renouvelle ton adhésion dès maintenant :
        {$this->renewalUrl}

        À bientôt,
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
        $url = htmlspecialchars($this->renewalUrl, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        $inner = <<<HTML
        <p style="margin:0 0 20px;font-family:Inter,Arial,sans-serif;font-size:22px;font-weight:700;color:#e8edf4;line-height:1.3;">
          Adhésion expirée
        </p>

        <p style="margin:0 0 16px;font-family:Inter,Arial,sans-serif;font-size:15px;color:#e8edf4;line-height:1.65;">
          Bonjour <strong style="color:#9580f5;">{$name}</strong>,
        </p>

        <p style="margin:0 0 16px;font-family:Inter,Arial,sans-serif;font-size:15px;color:#b0bec8;line-height:1.65;">
          Ton adhésion ArchiLAN a expiré et ton accès aux avantages membres a été retiré. Tu peux te réinscrire à tout moment.
        </p>

        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
          <tr>
            <td style="background-color:#1a1840;border-left:3px solid #e0246a;border-radius:0 6px 6px 0;padding:12px 16px;margin-bottom:24px;">
              <p style="margin:0;font-family:Inter,Arial,sans-serif;font-size:13px;color:#b0bec8;line-height:1.5;">
                ℹ️&nbsp; Renouvelle ton adhésion pour retrouver l'accès aux événements LAN et aux avantages membres.
              </p>
            </td>
          </tr>
        </table>

        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
          <tr><td height="20" style="font-size:0;line-height:0;">&nbsp;</td></tr>
        </table>

        <table role="presentation" cellspacing="0" cellpadding="0" border="0">
          <tr>
            <td style="border-radius:8px;background-color:#231470;">
              <a href="{$url}"
                 target="_blank"
                 style="display:inline-block;padding:14px 32px;font-family:Inter,Arial,sans-serif;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:8px;letter-spacing:0.2px;mso-padding-alt:14px 32px;">
                🔄&nbsp;&nbsp;Renouveler mon adhésion
              </a>
            </td>
          </tr>
        </table>

        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
          <tr><td height="28" style="font-size:0;line-height:0;">&nbsp;</td></tr>
        </table>

        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
          <tr>
            <td height="1" style="background-color:#281f52;font-size:0;line-height:0;">&nbsp;</td>
          </tr>
        </table>

        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
          <tr><td height="20" style="font-size:0;line-height:0;">&nbsp;</td></tr>
        </table>

        <p style="margin:0;font-family:Inter,Arial,sans-serif;font-size:12px;color:#4a5568;line-height:1.6;">
          Le bouton ne fonctionne pas ? Copie ce lien dans ton navigateur :<br>
          <a href="{$url}" style="color:#7a8ba8;word-break:break-all;">{$url}</a>
        </p>
        HTML;

        $logoUrl = '' !== $this->siteUrl ? rtrim($this->siteUrl, '/').'/images/logo.webp' : '';

        return $this->htmlLayout($inner, 'Ton adhésion ArchiLAN a expiré - renouvelle-la pour retrouver l\'accès.', $logoUrl);
    }
}
