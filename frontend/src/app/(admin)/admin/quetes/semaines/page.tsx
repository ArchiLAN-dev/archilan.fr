import type { Metadata } from "next";

import { AdminQuestsPage } from "@/features/wallet/admin-quests-page";

export const metadata: Metadata = {
  title: "Semaines de quêtes",
};

export default function AdminQuestWeeksPage() {
  return <AdminQuestsPage view="weeks" />;
}
