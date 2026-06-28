import type { Metadata } from "next";

import { AccountRegistrations } from "@/features/auth/account-registrations";

export const metadata: Metadata = { title: "Inscriptions" };

export default function InscriptionsPage() {
  return <AccountRegistrations />;
}
