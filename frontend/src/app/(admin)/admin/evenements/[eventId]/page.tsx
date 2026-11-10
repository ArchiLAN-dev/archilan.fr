import type { Metadata } from "next";

import { AdminEventHub } from "@/features/admin/event-hub/admin-event-hub";

export const metadata: Metadata = { title: "Événement" };

type Props = { params: Promise<{ eventId: string }> };

export default async function AdminEventHubPage({ params }: Props) {
  const { eventId } = await params;

  return (
    <section className="grid gap-6 px-4 pb-10 pt-6 lg:pt-10">
      <AdminEventHub eventId={eventId} />
    </section>
  );
}
