import type { Metadata } from "next";

import { AdminHelpGuides } from "@/features/admin/admin-help-guides";

export const metadata: Metadata = {
  title: "Guides d'aide",
};

/** Story 53.5: the help guides. */
export default function AdminAidePage() {
  return <AdminHelpGuides />;
}
