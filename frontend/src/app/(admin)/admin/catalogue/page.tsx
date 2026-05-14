import type { Metadata } from "next";
import { AdminCatalogueSyncPage } from "@/features/admin/admin-catalogue-sync-page";

export const metadata: Metadata = {
  title: "Synchronisation catalogue",
};

export default function CataloguePage() {
  return <AdminCatalogueSyncPage />;
}
