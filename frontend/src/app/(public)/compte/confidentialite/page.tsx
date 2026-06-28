import type { Metadata } from "next";

import { PrivacySection } from "@/features/auth/account-profile";

export const metadata: Metadata = { title: "Confidentialité" };

export default function ConfidentialitePage() {
  return <PrivacySection />;
}
