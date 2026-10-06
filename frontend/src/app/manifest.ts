import type { MetadataRoute } from "next";

/**
 * The web app manifest (story 40.4): ArchiLAN installs on a phone's home screen like an app - the only way iOS
 * delivers push notifications (story 40.2).
 */
export default function manifest(): MetadataRoute.Manifest {
  return {
    id: "/",
    name: "ArchiLAN",
    short_name: "ArchiLAN",
    description: "Événements, parties et communauté Archipelago d'ArchiLAN.",
    lang: "fr",
    start_url: "/",
    scope: "/",
    display: "standalone",
    background_color: "#0a1629",
    theme_color: "#0a1629",
    icons: [
      { src: "/icons/icon-192.png", sizes: "192x192", type: "image/png", purpose: "any" },
      { src: "/icons/icon-512.png", sizes: "512x512", type: "image/png", purpose: "any" },
      { src: "/icons/icon-maskable-512.png", sizes: "512x512", type: "image/png", purpose: "maskable" },
    ],
  };
}
