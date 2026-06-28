import type { Metadata } from "next";

import { CommunityProfileCustomizationForm } from "@/features/community/community-profile-customization-form";

export const metadata: Metadata = { title: "Profil" };

export default function ProfilPage() {
  return <CommunityProfileCustomizationForm />;
}
