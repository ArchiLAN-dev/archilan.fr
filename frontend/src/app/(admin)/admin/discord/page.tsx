import type { Metadata } from "next";
import { cookies } from "next/headers";

import { AdminDiscordDashboard } from "@/features/admin/admin-discord-dashboard";
import { fetchDiscordBotStatus, fetchDiscordBotUsers } from "@/features/admin/discord-bot-api";

export const metadata: Metadata = {
  title: "Discord Bot",
  description: "Statut du bot Discord et synchronisation des rôles ArchiLAN.",
  openGraph: { title: "Discord Bot - Administration ArchiLAN" },
};

export default async function DiscordPage() {
  const cookieHeader = (await cookies()).toString();
  const requestInit: RequestInit = cookieHeader !== "" ? { headers: { cookie: cookieHeader } } : {};
  const [initialStatus, initialUsers] = await Promise.all([
    fetchDiscordBotStatus(requestInit),
    fetchDiscordBotUsers(1, 50, requestInit),
  ]);

  return <AdminDiscordDashboard initialStatus={initialStatus} initialUsers={initialUsers} />;
}
