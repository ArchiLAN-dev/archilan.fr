import Link from "next/link";
import { LayoutGrid } from "lucide-react";

import { registrantHubPath } from "./registrant-hub-api";

/** Story 53.7: the way back to the registrant space, on top of the registration's pages. */
export async function HubLink({ params }: { params: Promise<{ eventSlug: string }> }) {
  const { eventSlug } = await params;
  return <HubLinkView eventSlug={eventSlug} />;
}

export function HubLinkView({ eventSlug }: { eventSlug: string }) {
  return (
    <Link
      className="mb-6 inline-flex items-center gap-2 text-sm font-semibold text-accent-text hover:text-accent-text-hover"
      href={registrantHubPath(eventSlug)}
    >
      <LayoutGrid aria-hidden="true" className="size-4" />
      Mon espace pour l&apos;événement
    </Link>
  );
}
