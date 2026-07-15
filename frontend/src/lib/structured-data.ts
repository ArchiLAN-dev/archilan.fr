import { externalLinks } from "@/lib/external-links";
import { env } from "@/lib/env";

/**
 * JSON-LD builders + the single escaped serializer they all share.
 *
 * schema.org objects are heterogeneous, so builders return `Record<string, unknown>`.
 * Absolute urls (logo, org/site url, breadcrumb items) are built from `env.appUrl` -
 * structured-data values are absolute by nature, unlike the relative canonicals in `seo.ts`.
 */
type JsonLdObject = Record<string, unknown>;

const SITE_NAME = "ArchiLAN";

function absolute(path: string): string {
  return new URL(path, env.appUrl).toString();
}

/**
 * Escape the three characters that could break out of a `<script>` context. The result is
 * still valid JSON (only `<`, `>`, `&` become `\uXXXX` escapes), so it round-trips via `JSON.parse`.
 */
export function serializeJsonLd(data: JsonLdObject): string {
  return JSON.stringify(data)
    .replace(/</g, "\\u003c")
    .replace(/>/g, "\\u003e")
    .replace(/&/g, "\\u0026");
}

export function organizationJsonLd(): JsonLdObject {
  return {
    "@context": "https://schema.org",
    "@type": "Organization",
    name: SITE_NAME,
    url: env.appUrl,
    logo: absolute("/images/logo.webp"),
    email: "contact@archilan.fr",
    sameAs: [externalLinks.twitch, externalLinks.archilanDiscord],
    address: {
      "@type": "PostalAddress",
      streetAddress: "26 rue de la Gantière",
      postalCode: "63000",
      addressLocality: "Clermont-Ferrand",
      addressCountry: "FR",
    },
  };
}

export function websiteJsonLd(): JsonLdObject {
  return {
    "@context": "https://schema.org",
    "@type": "WebSite",
    name: SITE_NAME,
    url: env.appUrl,
  };
}

export function breadcrumbJsonLd(items: { name: string; path: string }[]): JsonLdObject {
  return {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    itemListElement: items.map((item, index) => ({
      "@type": "ListItem",
      position: index + 1,
      name: item.name,
      item: absolute(item.path),
    })),
  };
}

/** Organization reference (with logo) for use as an Article `publisher`. */
export function publisherRef(): JsonLdObject {
  return {
    "@type": "Organization",
    name: SITE_NAME,
    logo: {
      "@type": "ImageObject",
      url: absolute("/images/logo.webp"),
    },
  };
}
