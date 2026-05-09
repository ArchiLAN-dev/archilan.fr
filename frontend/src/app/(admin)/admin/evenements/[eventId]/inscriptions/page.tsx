import type { Metadata } from "next";

import { AdminRegistrationDashboard } from "@/features/admin/admin-registration-dashboard";

export const metadata: Metadata = {
  title: "Inscriptions",
};

type Props = {
  params: Promise<{ eventId: string }>;
};

export default function AdminRegistrationsPage({ params }: Props) {
  return <AdminRegistrationDashboard params={params} />;
}
