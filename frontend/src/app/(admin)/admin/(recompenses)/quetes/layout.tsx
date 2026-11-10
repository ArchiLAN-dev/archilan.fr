import type { ReactNode } from "react";

import { AdminQuestsHeader } from "@/features/wallet/admin-quests-tabs";

/** Story 41.15: the weeks and the quest types, two sub-pages of one page behind tabs. */
export default function AdminQuetesLayout({ children }: { children: ReactNode }) {
  return (
    <section className="grid gap-6 p-6 md:p-8">
      <AdminQuestsHeader />
      {children}
    </section>
  );
}
