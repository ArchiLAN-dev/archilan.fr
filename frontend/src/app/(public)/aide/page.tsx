import { HelpGuideIndex } from "@/features/content/help-guide-views";
import { getHelpGuides } from "@/features/content/help-guides-api";
import { buildPageMetadata } from "@/lib/seo";

export const revalidate = 300; // ISR, dropped by the API on each guide write (story 53.5)

export const metadata = buildPageMetadata({
  title: "Aide",
  description: "Les guides d'ArchiLAN : installer Archipelago, les pelles, le Passeur, le blind test et tout ce qui fait une LAN.",
  path: "/aide",
});

/** Story 53.5: the help guides. */
export default async function HelpIndexPage() {
  return <HelpGuideIndex guides={await getHelpGuides()} />;
}
