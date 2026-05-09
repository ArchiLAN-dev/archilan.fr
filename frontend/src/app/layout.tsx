import type { Metadata } from "next";
import "./globals.css";
import { env } from "@/lib/env";

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
    apple: "/images/logo.webp",
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
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="fr" className="h-full antialiased">
      <body className="min-h-full">{children}</body>
    </html>
  );
}
