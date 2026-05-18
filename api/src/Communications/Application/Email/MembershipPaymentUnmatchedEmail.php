<?php

declare(strict_types=1);

namespace App\Communications\Application\Email;

use App\Communications\Application\ArchilanEmail;

final class MembershipPaymentUnmatchedEmail extends ArchilanEmail
{
    public function __construct(
        private readonly string $recipientEmail,
        private readonly ?string $recipientFirstName,
        private readonly string $helloassoOrderId,
        private readonly string $discordInviteUrl,
        private readonly string $siteUrl = '',
    ) {
    }

    public function to(): string
    {
        return $this->recipientEmail;
    }

    public function toName(): ?string
    {
        return $this->recipientFirstName;
    }

    public function subject(): string
    {
        return 'Ton paiement ArchiLAN a bien été reçu - action requise';
    }

    public function textBody(): string
    {
        $name = $this->recipientFirstName ?? $this->recipientEmail;
        $discordLine = '' !== $this->discordInviteUrl
            ? "\nPour finaliser ton adhésion, rejoins notre serveur Discord et contacte un administrateur :\n{$this->discordInviteUrl}\n"
            : "\nPour finaliser ton adhésion, contacte directement un administrateur ArchiLAN.\n";

        return <<<TEXT
        Bonjour {$name},

        Ton paiement HelloAsso (réf. #{$this->helloassoOrderId}) a bien été reçu, merci !

        Cependant, nous n'avons pas pu rattacher ce paiement automatiquement à un compte archilan.fr. Cela peut arriver si l'adresse email utilisée sur HelloAsso est différente de celle de ton compte ArchiLAN.
        {$discordLine}
        Merci de te signaler avec la référence de ton paiement : #{$this->helloassoOrderId}

        L'équipe ArchiLAN
        TEXT;
    }

    public function htmlBody(): string
    {
        $name = htmlspecialchars(
            $this->recipientFirstName ?? $this->recipientEmail,
            \ENT_QUOTES | \ENT_SUBSTITUTE,
            'UTF-8',
        );
        $orderId = htmlspecialchars($this->helloassoOrderId, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        $discordBlock = '';
        if ('' !== $this->discordInviteUrl) {
            $discordUrl = htmlspecialchars($this->discordInviteUrl, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
            $discordBlock = <<<HTML

            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
              <tr><td height="16" style="font-size:0;line-height:0;">&nbsp;</td></tr>
            </table>

            <table role="presentation" cellspacing="0" cellpadding="0" border="0">
              <tr>
                <td style="border-radius:8px;background-color:#231470;">
                  <a href="{$discordUrl}"
                     target="_blank"
                     style="display:inline-block;padding:14px 32px;font-family:Inter,Arial,sans-serif;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:8px;letter-spacing:0.2px;mso-padding-alt:14px 32px;">
                    💬&nbsp;&nbsp;Rejoindre le Discord ArchiLAN
                  </a>
                </td>
              </tr>
            </table>
            HTML;
        }

        $inner = <<<HTML
        <p style="margin:0 0 20px;font-family:Inter,Arial,sans-serif;font-size:22px;font-weight:700;color:#e8edf4;line-height:1.3;">
          Paiement reçu - action requise
        </p>

        <p style="margin:0 0 16px;font-family:Inter,Arial,sans-serif;font-size:15px;color:#e8edf4;line-height:1.65;">
          Bonjour <strong style="color:#9580f5;">{$name}</strong>,
        </p>

        <p style="margin:0 0 16px;font-family:Inter,Arial,sans-serif;font-size:15px;color:#b0bec8;line-height:1.65;">
          Ton paiement HelloAsso a bien été reçu, merci&nbsp;! Cependant, nous n'avons pas pu le rattacher automatiquement à un compte <strong style="color:#e8edf4;">archilan.fr</strong>. Cela peut arriver si l'adresse email utilisée sur HelloAsso est différente de celle de ton compte.
        </p>

        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
          <tr>
            <td style="background-color:#1a1840;border-left:3px solid #e0246a;border-radius:0 6px 6px 0;padding:12px 16px;">
              <p style="margin:0;font-family:Inter,Arial,sans-serif;font-size:14px;color:#b0bec8;line-height:1.5;">
                🧾&nbsp; Référence de ton paiement : <strong style="color:#e8edf4;">#{$orderId}</strong>
              </p>
            </td>
          </tr>
        </table>

        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
          <tr><td height="16" style="font-size:0;line-height:0;">&nbsp;</td></tr>
        </table>

        <p style="margin:0 0 8px;font-family:Inter,Arial,sans-serif;font-size:15px;color:#b0bec8;line-height:1.65;">
          Contacte un administrateur sur notre serveur Discord en indiquant cette référence - nous rattacherons ton adhésion manuellement.
        </p>
        {$discordBlock}

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
          Si tu n'as pas effectué ce paiement, ignore ce message et contacte-nous.
        </p>
        HTML;

        $logoUrl = '' !== $this->siteUrl ? rtrim($this->siteUrl, '/').'/images/logo.webp' : '';

        return $this->htmlLayout($inner, 'Paiement reçu mais non rattaché automatiquement - référence #'.$this->helloassoOrderId.'.', $logoUrl);
    }
}
