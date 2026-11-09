import type { Metadata } from "next";

import { AdminPresenceTimelinePage } from "@/features/presence/admin-presence-timeline";

export const metadata: Metadata = {
  title: "Présences de l'événement",
};

type Props = {
  params: Promise<{ eventId: string }>;
};

export default function EventPresencePage({ params }: Props) {
  return <AdminPresenceTimelinePage params={params} />;
}
