import type { ReactNode } from "react";

import { AdminSectionTabs } from "@/features/admin/admin-section-tabs";

/** Story 3.20: news and announcements, one menu entry, its pages behind tabs at their own addresses. */
export default function AdminPublicationsLayout({ children }: { children: ReactNode }) {
  return (
    <>
      <AdminSectionTabs section="publications" />
      {children}
    </>
  );
}
