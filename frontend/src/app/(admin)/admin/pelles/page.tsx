import type { Metadata } from "next";

import { AdminPellesDashboard } from "@/features/wallet/admin-pelles-dashboard";

export const metadata: Metadata = {
  title: "Pelles",
};

export default function AdminPellesPage() {
  return <AdminPellesDashboard />;
}
