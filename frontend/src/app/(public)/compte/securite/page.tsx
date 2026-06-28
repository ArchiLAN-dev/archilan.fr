import type { Metadata } from "next";

import { AccountSecuritySection } from "@/features/auth/account-security-section";

export const metadata: Metadata = { title: "Connexions & sécurité" };

type SecurityPageProps = {
  searchParams: Promise<{ [key: string]: string | string[] | undefined }>;
};

export default async function SecuritePage({ searchParams }: SecurityPageProps) {
  const { discord_linked, discord_link_error } = await searchParams;

  return (
    <AccountSecuritySection
      discordLinked={typeof discord_linked === "string" ? discord_linked : undefined}
      discordLinkError={typeof discord_link_error === "string" ? discord_link_error : undefined}
    />
  );
}
