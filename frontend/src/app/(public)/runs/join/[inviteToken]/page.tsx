import type { Metadata } from "next";
import { JoinPage } from "@/features/personal-runs/join-page";

export const metadata: Metadata = {
  title: "Rejoindre une partie",
  description: "Rejoins une partie Archipelago personnelle via lien d'invitation.",
};

export default async function JoinRunPage({
  params,
}: {
  params: Promise<{ inviteToken: string }>;
}) {
  const { inviteToken } = await params;
  return <JoinPage inviteToken={inviteToken} />;
}
