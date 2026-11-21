import type { Metadata } from "next";

import { QuestsPanel } from "@/features/wallet/quests-panel";

// Story 41.73: the quests of the week on a page of their own, out of « Pelles ».
export const metadata: Metadata = { title: "Quêtes" };

export default function QuetesPage() {
  return <QuestsPanel />;
}
