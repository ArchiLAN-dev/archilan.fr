import type { Metadata } from "next";
import { AdminAchievementsDashboard } from "@/features/admin/admin-achievements-dashboard";

export const metadata: Metadata = {
  title: "Succès",
};

export default function AdminAchievementsPage() {
  return <AdminAchievementsDashboard />;
}
