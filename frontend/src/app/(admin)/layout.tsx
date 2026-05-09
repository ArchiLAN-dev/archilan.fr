import { AdminShell } from "@/components/admin-shell";

export default function AdminGroupLayout({ children }: { children: React.ReactNode }) {
  return <AdminShell>{children}</AdminShell>;
}
