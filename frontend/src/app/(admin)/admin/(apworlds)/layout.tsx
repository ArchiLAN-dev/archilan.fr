import type { ReactNode } from "react";

import { AdminSectionTabs } from "@/features/admin/admin-section-tabs";

/** Story 3.20: the catalogue, apworld health and the Archipelago help, one menu entry, its pages behind tabs at their own addresses. */
export default function AdminApworldsLayout({ children }: { children: ReactNode }) {
  return (
    <>
      <AdminSectionTabs section="apworlds" />
      {children}
    </>
  );
}
