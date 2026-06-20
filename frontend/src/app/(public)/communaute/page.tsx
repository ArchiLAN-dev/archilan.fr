import type { Metadata } from "next";
import { CommunityDirectory } from "@/features/community/community-directory";

export const metadata: Metadata = {
  title: "Communauté",
  description: "Parcoure les joueurs ArchiLAN : top du classement, membres récemment actifs et tes amis.",
  openGraph: {
    title: "Communauté ArchiLAN",
    description: "Le classement et l'annuaire des joueurs ArchiLAN.",
  },
};

export default function CommunautePage() {
  return (
    <div className="mx-auto w-full max-w-5xl">
      <CommunityDirectory />
    </div>
  );
}
