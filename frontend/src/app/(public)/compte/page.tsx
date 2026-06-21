import type { Metadata } from "next";
import { AccountTabs } from "@/features/auth/account-tabs";
import { RequireAuth } from "@/features/auth/require-auth";

export const metadata: Metadata = {
  title: "Mon espace",
  description:
    "Consulte tes inscriptions, gère ton profil et accède à tes sessions Archipelago.",
};

type AccountPageProps = {
  searchParams: Promise<{ [key: string]: string | string[] | undefined }>;
};

export default async function AccountPage({ searchParams }: AccountPageProps) {
  const { discord_linked, discord_link_error } = await searchParams;
  const discordLinked = typeof discord_linked === "string" ? discord_linked : undefined;
  const discordLinkError = typeof discord_link_error === "string" ? discord_link_error : undefined;

  return (
    <RequireAuth>
      <div className="mx-auto grid max-w-3xl gap-10">
        <header>
          <p className="mb-3 text-sm font-semibold uppercase tracking-[0.18em] text-accent-text">
            Compte ArchiLAN
          </p>
          <h1 className="font-heading text-4xl font-bold leading-tight text-foreground md:text-5xl">
            Mon espace
          </h1>
        </header>

        <AccountTabs discordLinked={discordLinked} discordLinkError={discordLinkError} />
      </div>
    </RequireAuth>
  );
}
