import type { Metadata } from "next";

import { AdminEventPellesPage } from "@/features/wallet/admin-event-pelles-page";

export const metadata: Metadata = {
  title: "Pelles de l'événement",
};

type Props = {
  params: Promise<{ eventId: string }>;
};

export default function EventPellesPage({ params }: Props) {
  return <AdminEventPellesPage params={params} />;
}
