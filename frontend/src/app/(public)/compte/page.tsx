import { redirect } from "next/navigation";

import { AccountOverview } from "@/features/auth/account-overview";

// Old `?tab=` deep links (story 30.35) → native per-section routes.
const TAB_ROUTES: Record<string, string> = {
  profil: "/compte/profil",
  amis: "/compte/amis",
  activite: "/compte/activite",
  inscriptions: "/compte/inscriptions",
  parties: "/compte/parties",
  adhesion: "/compte/adhesion",
  confidentialite: "/compte/confidentialite",
  compte: "/compte/securite",
  securite: "/compte/securite",
};

type AccountPageProps = {
  searchParams: Promise<{ [key: string]: string | string[] | undefined }>;
};

export default async function AccountOverviewPage({ searchParams }: AccountPageProps) {
  const { tab, discord_linked, discord_link_error } = await searchParams;

  if (typeof tab === "string" && TAB_ROUTES[tab]) {
    redirect(TAB_ROUTES[tab]);
  }

  // Back-compat: the old Discord callback landed on /compte?discord_linked=… → forward to securite.
  if (typeof discord_linked === "string" || typeof discord_link_error === "string") {
    const q = new URLSearchParams();
    if (typeof discord_linked === "string") q.set("discord_linked", discord_linked);
    if (typeof discord_link_error === "string") q.set("discord_link_error", discord_link_error);
    redirect(`/compte/securite?${q.toString()}`);
  }

  return <AccountOverview />;
}
