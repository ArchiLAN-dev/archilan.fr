import type { MetadataRoute } from "next";

import { env } from "@/lib/env";

/**
 * robots.txt for archilan.fr.
 *
 * A `Disallow` blocks CRAWLING, which stops Google from ever seeing a page's
 * `<meta robots noindex>` - and a disallowed-but-linked URL can still get indexed
 * URL-only. So we disallow only zones where crawling is pure waste and no external
 * links are expected (admin, overlay, account, auth flows, registration funnels).
 * Player, stream and results pages are left crawlable so their existing meta noindex
 * is honoured. Route-group prefixes: `(admin)` -> `/admin/`, `(overlay)` -> `/o/`.
 */
export default function robots(): MetadataRoute.Robots {
  return {
    rules: {
      userAgent: "*",
      allow: "/",
      disallow: [
        "/admin/",
        "/o/",
        "/compte/",
        "/connexion",
        "/inscription",
        "/mot-de-passe-oublie",
        "/reinitialisation-mot-de-passe",
        "/confirmation-email",
        "/runs/",
        // A bare `/inscription` only matches the root-level auth page (prefix from root);
        // the per-event registration funnel needs its own wildcard rule.
        "/evenements/*/inscription",
      ],
    },
    sitemap: new URL("/sitemap.xml", env.appUrl).toString(),
  };
}
