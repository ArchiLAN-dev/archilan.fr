import type { Metadata } from "next";

import { AdminEventGameSelectionPage } from "@/features/admin/admin-event-game-selection-page";

export const metadata: Metadata = { title: "Sélection de jeux" };

export default async function Page({ params }: { params: Promise<{ eventId: string }> }) {
  const { eventId } = await params;
  return <AdminEventGameSelectionPage eventId={eventId} />;
}
