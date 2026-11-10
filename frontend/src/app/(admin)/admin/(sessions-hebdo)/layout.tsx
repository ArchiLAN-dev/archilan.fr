import type { ReactNode } from "react";

import { AdminSectionTabs } from "@/features/admin/admin-section-tabs";

/** Story 3.20: weekly runs and session config, one menu entry, its pages behind tabs at their own addresses. */
export default function AdminSessionsLayout({ children }: { children: ReactNode }) {
  return (
    <>
      <AdminSectionTabs section="sessions" />
      {children}
    </>
  );
}
