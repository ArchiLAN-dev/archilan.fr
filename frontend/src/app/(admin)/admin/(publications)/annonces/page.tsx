import type { Metadata } from "next";

import { AdminHomeAnnouncements } from "@/features/admin/admin-home-announcements";

export const metadata: Metadata = {
  title: "Annonces de l'accueil",
};

export default function AdminAnnoncesPage() {
  return <AdminHomeAnnouncements />;
}
