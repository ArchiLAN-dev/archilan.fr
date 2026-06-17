import type { Metadata } from "next";

// Bare route group for OBS browser sources: no PublicShell, no nav/footer. The root layout still
// provides <html>/<body>; the overlay pages make the document background transparent (or a chroma key)
// at runtime so OBS composites them over gameplay capture. Overlays must never be indexed.
export const metadata: Metadata = {
  title: "Overlay",
  robots: { index: false, follow: false },
};

export default function OverlayLayout({ children }: { children: React.ReactNode }) {
  return <>{children}</>;
}
