import { PublicShell } from "@/components/public-shell";
import { getPartners } from "@/features/content/partners-api";
import { fetchDiscordStats } from "@/features/discord/discord-api";

export default async function PublicLayout({ children }: { children: React.ReactNode }) {
  // Story 30.50: the Discord counts of the header, read on the server and kept five minutes. Story 45.2: the footer
  // links to the partners only when there is one to show (the same cached read as the home page).
  const [discord, partners] = await Promise.all([fetchDiscordStats(), getPartners()]);
  return (
    <PublicShell discord={discord} hasPartners={partners.length > 0}>
      {children}
    </PublicShell>
  );
}
