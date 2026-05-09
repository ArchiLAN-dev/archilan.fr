import type { Metadata } from "next";
import { AdminGameLibraryDashboard } from "@/features/admin/admin-game-library-dashboard";

export const metadata: Metadata = {
  title: "Jeux",
};

export default function AdminGamesPage() {
  return <AdminGameLibraryDashboard />;
}
