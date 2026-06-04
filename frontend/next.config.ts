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
    remotePatterns: [
      { protocol: "https", hostname: "**" },
      { protocol: "http", hostname: "**" },
    ],
  },
  turbopack: {
    // Prevents Next 16 from walking up past frontend/ to a user-level package-lock.json
    // on Windows (observed when C:\Users\<user>\package-lock.json exists).
    root: path.resolve(__dirname),
  },
};

export default withBundleAnalyzer(nextConfig);
