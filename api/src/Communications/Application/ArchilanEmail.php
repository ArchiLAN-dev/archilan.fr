<?php

declare(strict_types=1);

namespace App\Communications\Application;

abstract class ArchilanEmail
{
    abstract public function to(): string;

    abstract public function toName(): ?string;

    abstract public function subject(): string;

    abstract public function textBody(): string;

    public function htmlBody(): ?string
    {
        return null;
    }

    /**
     * Wraps $innerHtml in the ArchiLAN transactional email chrome.
     *
     * $innerHtml should contain the card body: greeting, paragraphs, CTA button, etc.
     * $previewText is the one-liner shown by email clients in inbox listings.
     * $logoUrl should be an absolute URL to the logo image; falls back to text wordmark.
     */
    protected function htmlLayout(string $innerHtml, string $previewText = '', string $logoUrl = ''): string
    {
        $preview = '' !== $previewText
            ? '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;color:#0a1629;line-height:1px;">'
              .htmlspecialchars($previewText, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
              .'&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;</div>'
            : '';

        return <<<HTML
        <!DOCTYPE html>
        <html lang="fr" xmlns="http://www.w3.org/1999/xhtml">
        <head>
          <meta charset="UTF-8">
          <meta name="viewport" content="width=device-width,initial-scale=1">
          <meta http-equiv="X-UA-Compatible" content="IE=edge">
          <title>ArchiLAN</title>
          <!--[if mso]>
          <noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript>
          <![endif]-->
          <style>
            @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@700&display=swap');
            body,table,td,a{-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%}
            table,td{mso-table-lspace:0pt;mso-table-rspace:0pt}
            img{-ms-interpolation-mode:bicubic;border:0;height:auto;line-height:100%;outline:none;text-decoration:none}
            body{height:100%!important;margin:0!important;padding:0!important;width:100%!important;background-color:#0a1629}
            a[x-apple-data-detectors]{color:inherit!important;text-decoration:none!important;font-size:inherit!important;font-family:inherit!important;font-weight:inherit!important;line-height:inherit!important}
            u+#body a{color:inherit;text-decoration:none;font-size:inherit;font-family:inherit;font-weight:inherit;line-height:inherit}
            #MessageViewBody a{color:inherit;text-decoration:none;font-size:inherit;font-family:inherit;font-weight:inherit;line-height:inherit}
            @media only screen and (max-width:600px){
              .email-container{width:100%!important;margin:auto!important}
              .stack-column,.stack-column-center{display:block!important;width:100%!important;max-width:100%!important;direction:ltr!important}
            }
          </style>
        </head>
        <body id="body" style="margin:0;padding:0;background-color:#0a1629;font-family:Inter,Arial,Helvetica,sans-serif;">
          {$preview}

          <!-- Outer wrapper -->
          <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color:#0a1629;">
            <tr>
              <td align="center" style="padding:40px 20px 48px;">

                <!-- Email container -->
                <table role="presentation" class="email-container" cellspacing="0" cellpadding="0" border="0" width="560" style="margin:0 auto;">

                  <!-- ── HEADER ────────────────────────────────────────── -->
                  <tr>
                    <td align="center" style="padding-bottom:24px;">
                      {$this->renderLogo($logoUrl)}
                    </td>
                  </tr>

                  <!-- ── CARD ──────────────────────────────────────────── -->
                  <tr>
                    <td style="background-color:#111030;border:1px solid #281f52;border-radius:12px;overflow:hidden;">

                      <!-- Accent top bar -->
                      <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                        <tr>
                          <td height="3" style="background:linear-gradient(90deg,#231470 0%,#9580f5 60%,#e0246a 100%);font-size:0;line-height:0;">&nbsp;</td>
                        </tr>
                      </table>

                      <!-- Card body -->
                      <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                        <tr>
                          <td style="padding:36px 40px 40px;">
                            {$innerHtml}
                          </td>
                        </tr>
                      </table>

                    </td>
                  </tr>

                  <!-- ── FOOTER ────────────────────────────────────────── -->
                  <tr>
                    <td align="center" style="padding-top:24px;">
                      <p style="margin:0 0 6px;font-family:Inter,Arial,sans-serif;font-size:12px;color:#7a8ba8;line-height:1.5;">
                        © ArchiLAN · Association de jeux vidéo en réseau
                      </p>
                      <p style="margin:0;font-family:Inter,Arial,sans-serif;font-size:11px;color:#4a5568;line-height:1.5;">
                        Tu reçois cet email car une action a été effectuée sur ton compte ArchiLAN.<br>
                        Si tu n'es pas à l'origine de cette action, ignore ce message.
                      </p>
                    </td>
                  </tr>

                </table>
              </td>
            </tr>
          </table>

        </body>
        </html>
        HTML;
    }

    private function renderLogo(string $logoUrl): string
    {
        if ('' === $logoUrl) {
            return '<span style="font-family:\'Space Grotesk\',Inter,Arial,sans-serif;font-size:26px;font-weight:700;letter-spacing:-0.5px;color:#9580f5;">Archi<span style="color:#e8edf4;">LAN</span></span>';
        }

        $safeUrl = htmlspecialchars($logoUrl, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
        <img src="{$safeUrl}" alt="ArchiLAN" width="72" height="72"
             style="display:block;margin:0 auto 10px;width:72px;height:72px;border:0;outline:none;text-decoration:none;">
        <span style="font-family:'Space Grotesk',Inter,Arial,sans-serif;font-size:14px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:#7a8ba8;">ArchiLAN</span>
        HTML;
    }
}
