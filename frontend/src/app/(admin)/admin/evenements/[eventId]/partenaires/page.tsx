import type { Metadata } from "next";

import { AdminEventPartnersPage } from "@/features/admin/admin-event-partners";

export const metadata: Metadata = {
  title: "Partenaires de l'événement",
};

type Props = {
  params: Promise<{ eventId: string }>;
};

export default function EventPartnersPage({ params }: Props) {
  return <AdminEventPartnersPage params={params} />;
}
