import { Inter, Space_Grotesk } from "next/font/google";

// Self-hosted at build time (no runtime request to Google). `display: swap` avoids
// invisible text while the font loads. Exposed as CSS variables wired into the
// --font-sans / --font-heading tokens in globals.css.
export const inter = Inter({
  subsets: ["latin"],
  variable: "--font-inter",
  display: "swap",
});

export const spaceGrotesk = Space_Grotesk({
  subsets: ["latin"],
  variable: "--font-space-grotesk",
  display: "swap",
});
