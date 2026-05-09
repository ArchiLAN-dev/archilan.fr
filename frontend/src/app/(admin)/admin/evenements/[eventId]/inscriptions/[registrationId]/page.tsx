import type { Metadata } from "next";

import { AdminRegistrationDetail } from "@/features/admin/admin-registration-detail";

export const metadata: Metadata = {
  title: "Détail d'inscription",
};

type Props = {
  params: Promise<{ eventId: string; registrationId: string }>;
};

export default function AdminRegistrationDetailPage({ params }: Props) {
  return <AdminRegistrationDetail params={params} />;
}
