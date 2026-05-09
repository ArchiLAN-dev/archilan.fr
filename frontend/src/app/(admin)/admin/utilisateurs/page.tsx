import type { Metadata } from "next";
import { AdminUserDirectory } from "@/features/admin/admin-user-directory";

export const metadata: Metadata = {
  title: "Utilisateurs",
};

export default function AdminUsersPage() {
  return <AdminUserDirectory />;
}
