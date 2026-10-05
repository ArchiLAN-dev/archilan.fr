import type { Metadata } from "next";

import { AdminQuestsPage } from "@/features/wallet/admin-quests-page";

export const metadata: Metadata = {
  title: "Quêtes hebdo",
};

export default function AdminQuetesPage() {
  return <AdminQuestsPage />;
}
