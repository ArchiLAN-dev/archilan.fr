import type { Metadata } from "next";
import { PersonalRunsListPage } from "@/features/personal-runs/personal-runs-list-page";

export const metadata: Metadata = {
  title: "Mes parties",
  description: "Gère tes parties Archipelago personnelles.",
};

export default function RunsPage() {
  return <PersonalRunsListPage />;
}
