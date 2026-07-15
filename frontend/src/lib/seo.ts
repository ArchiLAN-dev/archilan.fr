import type { Metadata } from "next";

/**
 * Shared builder for a public page's canonical + Open Graph + Twitter block.
 *
 * Next merges metadata shallowly per top-level field: a page that sets `openGraph`
 * replaces the parent's `openGraph` wholesale (no field-by-field merge), so we always
 * emit a full `openGraph` with an `images` array rather than relying on inheritance.
 * `alternates.canonical` and `openGraph.url` are relative paths - Next resolves them
 * against the root layout's `metadataBase` (`new URL(env.appUrl)`), so this helper stays
 * a pure function of its input and never touches `process.env`.
 */
export const SITE_NAME = "ArchiLAN";

const DEFAULT_OG_IMAGE = {
  url: "/images/events/lan-photo-1.webp",
  width: 6000,
  height: 4000,
  alt: "Participants jouant lors d'un événement ArchiLAN",
};

type PageMetaInput = {
  title: string;
  description: string;
  /** Absolute path the page lives at, e.g. `/evenements` or `/`. Becomes the canonical + OG url. */
  path: string;
  ogType?: "website" | "article";
  images?: NonNullable<NonNullable<Metadata["openGraph"]>["images"]>;
  /** Home only: skip the `%s | ArchiLAN` title template (it would double the brand). */
  absoluteTitle?: boolean;
};

export function buildPageMetadata({
  title,
  description,
  path,
  ogType = "website",
  images,
  absoluteTitle = false,
}: PageMetaInput): Metadata {
  const ogTitle = absoluteTitle ? title : `${title} | ${SITE_NAME}`;

  return {
    title: absoluteTitle ? { absolute: title } : title,
    description,
    alternates: { canonical: path },
    openGraph: {
      title: ogTitle,
      description,
      url: path,
      siteName: SITE_NAME,
      type: ogType,
      locale: "fr_FR",
      images: images ?? [DEFAULT_OG_IMAGE],
    },
    twitter: {
      card: "summary_large_image",
      title: ogTitle,
      description,
    },
  };
}