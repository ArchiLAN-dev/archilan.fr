import type { Metadata } from "next";
import { AdminModerationDashboard } from "@/features/admin/admin-moderation-dashboard";

export const metadata: Metadata = {
  title: "Modération",
};

export default function AdminModerationPage() {
  return <AdminModerationDashboard />;
}
