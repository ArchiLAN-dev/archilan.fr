import type { Metadata } from "next";

import { AdminSessionConfigPage } from "@/features/admin/admin-session-config-page";

export const metadata: Metadata = {
  title: "Configuration des sessions",
  description: "Options serveur et génération Archipelago par type de session (privé / événement / weekly).",
  openGraph: {
    title: "Configuration des sessions - Administration ArchiLAN",
  },
};

export default function AdminSessionsConfigRoute() {
  return <AdminSessionConfigPage />;
}
