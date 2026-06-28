import type { Metadata } from "next";

import { ProfileSlugEditor } from "@/features/auth/profile-slug-editor";
import { CommunityProfileCustomizationForm } from "@/features/community/community-profile-customization-form";

export const metadata: Metadata = { title: "Profil" };

export default function ProfilPage() {
  return (
    <div className="grid gap-6">
      <ProfileSlugEditor />
      <CommunityProfileCustomizationForm />
    </div>
  );
}
