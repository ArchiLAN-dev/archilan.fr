import type { Metadata, Viewport } from "next";
import "./globals.css";
import { env } from "@/lib/env";
import { inter, spaceGrotesk } from "./fonts";
import { JsonLd } from "@/components/json-ld";
import { organizationJsonLd, websiteJsonLd } from "@/lib/structured-data";

/** Story 40.4: the browser bar and the installed app take the site background. */
export const viewport: Viewport = {
  themeColor: "#0a1629",
};

export const metadata: Metadata = {
  metadataBase: new URL(env.appUrl),
  title: {
    default: "ArchiLAN",
    template: "%s | ArchiLAN",
  },
  description:
    "ArchiLAN organise des événements Archipelago en France - LAN parties coopératives, multiworld randomizer, communauté gaming.",
  icons: {
    icon: "/images/logo.webp",
    // Story 40.4: iOS takes a PNG for the home screen, not WebP.
    apple: "/icons/apple-touch-icon.png",
  },
  // Story 40.4: opened from the home screen, the site runs full screen like an app (iOS only sends pushes there).
  appleWebApp: {
    capable: true,
    title: "ArchiLAN",
    statusBarStyle: "black-translucent",
  },
  openGraph: {
    type: "website",
    locale: "fr_FR",
    siteName: "ArchiLAN",
    title: "ArchiLAN - LAN Randomizer Multiworld",
    description:
      "ArchiLAN organise des événements Archipelago en France - LAN parties coopératives, multiworld randomizer, communauté gaming.",
    images: [
      {
        url: "/images/events/lan-photo-1.webp",
        width: 6000,
        height: 4000,
        alt: "Participants jouant lors d'un événement ArchiLAN",
      },
    ],
  },
  // Pages that never declare their own `twitter` inherit these defaults (metadata
  // merges shallowly per field); pages built via buildPageMetadata override them.
  twitter: {
    card: "summary_large_image",
    title: "ArchiLAN - LAN Randomizer Multiworld",
    description:
      "ArchiLAN organise des événements Archipelago en France - LAN parties coopératives, multiworld randomizer, communauté gaming.",
  },
  // Google Search Console meta-tag verification, only when the token env is set.
  ...(env.gscVerification ? { verification: { google: env.gscVerification } } : {}),
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="fr" className={`h-full antialiased ${inter.variable} ${spaceGrotesk.variable}`}>
      <body className="min-h-full">
        <JsonLd data={organizationJsonLd()} />
        <JsonLd data={websiteJsonLd()} />
        {children}
      </body>
    </html>
  );
}
