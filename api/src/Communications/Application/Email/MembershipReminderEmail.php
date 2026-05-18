<?php

declare(strict_types=1);

namespace App\Communications\Application\Email;

use App\Communications\Application\ArchilanEmail;

final class MembershipReminderEmail extends ArchilanEmail
{
    public function __construct(
        private readonly string $recipientEmail,
        private readonly ?string $recipientDisplayName,
        private readonly int $daysLeft,
        private readonly string $expiryDateFormatted,
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
        if (30 === $this->daysLeft) {
            return 'Votre adhésion ArchiLAN expire dans 30 jours';
        }

        return 'Plus que 7 jours - renouvelez votre adhésion ArchiLAN';
    }

    public function textBody(): string
    {
        $name = $this->recipientDisplayName ?? $this->recipientEmail;
        $subject = $this->subject();

        return <<<TEXT
        Bonjour {$name},

        {$subject}.

        Ton adhésion expire le {$this->expiryDateFormatted}. Renouvelle-la dès maintenant pour continuer à profiter des événements ArchiLAN.

        Renouveler mon adhésion : {$this->renewalUrl}

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
        $expiry = htmlspecialchars($this->expiryDateFormatted, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        $urgencyColor = 30 === $this->daysLeft ? '#e89420' : '#e0246a';
        $urgencyEmoji = 30 === $this->daysLeft ? '⏳' : '⚠️';
        $daysText = 30 === $this->daysLeft ? '30 jours' : '7 jours';

        $heading = htmlspecialchars($this->subject(), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        $inner = <<<HTML
        <p style="margin:0 0 20px;font-family:Inter,Arial,sans-serif;font-size:22px;font-weight:700;color:#e8edf4;line-height:1.3;">
          {$urgencyEmoji} {$heading}
        </p>

        <p style="margin:0 0 16px;font-family:Inter,Arial,sans-serif;font-size:15px;color:#e8edf4;line-height:1.65;">
          Bonjour <strong style="color:#9580f5;">{$name}</strong>,
        </p>

        <p style="margin:0 0 16px;font-family:Inter,Arial,sans-serif;font-size:15px;color:#b0bec8;line-height:1.65;">
          Ton adhésion ArchiLAN expire dans <strong style="color:{$urgencyColor};">{$daysText}</strong>, le <strong style="color:#e8edf4;">{$expiry}</strong>. Renouvelle-la pour continuer à participer à nos événements LAN.
        </p>

        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
          <tr><td height="8" style="font-size:0;line-height:0;">&nbsp;</td></tr>
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
        $preview = 'Ton adhésion ArchiLAN expire dans '.$daysText.' - renouvelle-la maintenant.';

        return $this->htmlLayout($inner, $preview, $logoUrl);
    }
}
