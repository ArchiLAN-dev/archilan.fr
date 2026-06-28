import type { Metadata } from "next";

import { PersonalRunsListPage } from "@/features/personal-runs/personal-runs-list-page";

export const metadata: Metadata = { title: "Mes parties" };

export default function PartiesPage() {
  return <PersonalRunsListPage embedded />;
}
