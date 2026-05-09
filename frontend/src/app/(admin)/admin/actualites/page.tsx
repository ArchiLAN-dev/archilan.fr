import type { Metadata } from "next";

import { AdminContentDashboard } from "@/features/admin/admin-content-dashboard";

export const metadata: Metadata = {
  title: "Actualités",
};

export default function AdminActualitesPage() {
  return <AdminContentDashboard />;
}
