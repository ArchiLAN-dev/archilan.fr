import type { NextConfig } from "next";
import path from "node:path";

const nextConfig: NextConfig = {
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

export default nextConfig;
