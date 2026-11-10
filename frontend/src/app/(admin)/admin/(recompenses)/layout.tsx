import type { ReactNode } from "react";

import { AdminSectionTabs } from "@/features/admin/admin-section-tabs";

/** Story 3.20: achievements, shop, quests and cosmetics, one menu entry, its pages behind tabs at their own addresses. */
export default function AdminRewardsLayout({ children }: { children: ReactNode }) {
  return (
    <>
      <AdminSectionTabs section="rewards" />
      {children}
    </>
  );
}
