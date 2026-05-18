import type { Metadata } from "next";
import { AdminMembershipDashboard } from "@/features/admin/admin-membership-dashboard";

export const metadata: Metadata = {
  title: "Adhésions",
};

export default function AdminMembershipsPage() {
  return (
    <div className="w-full px-4 py-6 md:py-8">
      <AdminMembershipDashboard />
    </div>
  );
}
