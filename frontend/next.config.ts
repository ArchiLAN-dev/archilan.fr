import bundleAnalyzer from "@next/bundle-analyzer";
import type { NextConfig } from "next";
import path from "node:path";

const withBundleAnalyzer = bundleAnalyzer({
  enabled: process.env.ANALYZE === "true",
  openAnalyzer: false,
});

const nextConfig: NextConfig = {
  output: "standalone",
  // Allow Next.js jest to transform these ESM-only packages (needed for msw/node in tests).
  transpilePackages: ["msw", "rettime", "until-async", "@open-draft/deferred-promise"],
  images: {
    formats: ["image/avif", "image/webp"],
    // https only (no plaintext). Hostname stays broad because event/post covers can be
    // admin-entered arbitrary public URLs (coverImageMode: 'url'); since story 34.4 optimises
    // them, narrowing to a fixed host list would 400 legitimate covers.
    remotePatterns: [{ protocol: "https", hostname: "**" }],
  },
  async headers() {
    // Baseline security headers on every response. These govern how OUR pages behave, not our
    // ability to embed Twitch/HelloAsso (that is CSP frame-src, not added here) - so they are
    // safe for the consent-gated embed pages. No CSP: a strict one would break Next's inline
    // hydration scripts and the embeds.
    const securityHeaders = [
      { key: "X-Content-Type-Options", value: "nosniff" },
      { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
      { key: "X-Frame-Options", value: "SAMEORIGIN" },
      { key: "Strict-Transport-Security", value: "max-age=63072000; includeSubDomains; preload" },
    ];

    return [
      { source: "/:path*", headers: securityHeaders },
      {
        // Committed public assets (logo, event photos shipped in the build) are content-hashed
        // rarely-changing files; cache them hard. (/_next/static is already immutable by default.)
        source: "/images/:path*",
        headers: [{ key: "Cache-Control", value: "public, max-age=2592000, stale-while-revalidate=86400" }],
      },
    ];
  },
  turbopack: {
    // Prevents Next 16 from walking up past frontend/ to a user-level package-lock.json
    // on Windows (observed when C:\Users\<user>\package-lock.json exists).
    root: path.resolve(__dirname),
  },
};

export default withBundleAnalyzer(nextConfig);
