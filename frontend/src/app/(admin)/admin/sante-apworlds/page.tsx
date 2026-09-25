import type { Metadata } from "next";
import { AdminApworldHealthPage } from "@/features/admin/admin-apworld-health-page";

export const metadata: Metadata = {
  title: "Santé des apworlds",
};

export default function AdminApworldHealthRoute() {
  return <AdminApworldHealthPage />;
}
