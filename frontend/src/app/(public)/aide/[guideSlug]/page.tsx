import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { cache } from "react";

import { JsonLd } from "@/components/json-ld";
import { HelpGuideArticle } from "@/features/content/help-guide-views";
import { getHelpGuide, helpGuidePath } from "@/features/content/help-guides-api";
import { buildPageMetadata } from "@/lib/seo";
import { breadcrumbJsonLd } from "@/lib/structured-data";

export const revalidate = 300; // ISR, dropped by the API on each write of this guide (story 53.5)

type Props = {
  params: Promise<{ guideSlug: string }>;
};

const loadGuide = cache(getHelpGuide);

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { guideSlug } = await params;
  const guide = await loadGuide(guideSlug);
  if (guide === null) {
    return { title: "Guide introuvable", robots: { index: false, follow: false } };
  }

  return buildPageMetadata({ title: guide.title, description: guide.summary, path: helpGuidePath(guide.slug) });
}

/** Story 53.5: one help guide. `/aide/archipelago` keeps its own page, matched before this one. */
export default async function HelpGuidePage({ params }: Props) {
  const { guideSlug } = await params;
  const guide = await loadGuide(guideSlug);
  if (guide === null) notFound();

  return (
    <>
      <JsonLd
        data={breadcrumbJsonLd([
          { name: "Aide", path: "/aide" },
          { name: guide.title, path: helpGuidePath(guide.slug) },
        ])}
      />
      <HelpGuideArticle guide={guide} />
    </>
  );
}
