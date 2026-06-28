import type { Metadata } from "next";

import { MembershipSection } from "@/features/auth/membership-section";

export const metadata: Metadata = { title: "Adhésion" };

export default function AdhesionPage() {
  return <MembershipSection />;
}
