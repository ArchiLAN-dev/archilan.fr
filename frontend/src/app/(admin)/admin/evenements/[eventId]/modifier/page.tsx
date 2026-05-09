import type { Metadata } from "next";

import { AdminEventEditPage } from "@/features/admin/admin-event-edit-page";

export const metadata: Metadata = { title: "Modifier l'événement" };

export default async function Page({ params }: { params: Promise<{ eventId: string }> }) {
  const { eventId } = await params;
  return <AdminEventEditPage eventId={eventId} />;
}
