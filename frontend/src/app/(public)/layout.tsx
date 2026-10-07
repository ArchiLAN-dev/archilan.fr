import { PublicShell } from "@/components/public-shell";
import { fetchDiscordStats } from "@/features/discord/discord-api";

export default async function PublicLayout({ children }: { children: React.ReactNode }) {
  // Story 30.50: the Discord counts of the header, read on the server and kept five minutes.
  const discord = await fetchDiscordStats();
  return <PublicShell discord={discord}>{children}</PublicShell>;
}
