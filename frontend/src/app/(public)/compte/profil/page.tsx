import type { Metadata } from "next";

import { ProfileSettings } from "@/features/auth/profile-settings";

export const metadata: Metadata = { title: "Profil" };

export default function ProfilPage() {
  return <ProfileSettings />;
}
