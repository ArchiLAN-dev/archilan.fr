import type { Metadata } from "next";

import { AdminQuestsPage } from "@/features/wallet/admin-quests-page";

export const metadata: Metadata = {
  title: "Types de quêtes",
};

export default function AdminQuestTypesPage() {
  return <AdminQuestsPage view="types" />;
}
