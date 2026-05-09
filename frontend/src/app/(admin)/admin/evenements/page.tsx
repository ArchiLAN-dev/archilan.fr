import type { Metadata } from "next";
import { AdminEventDashboard } from "@/features/admin/admin-event-dashboard";

export const metadata: Metadata = {
  title: "Événements",
};

export default function AdminEventsPage() {
  return <AdminEventDashboard />;
}
