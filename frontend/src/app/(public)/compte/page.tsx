import type { Metadata } from "next";
import { AccountTabs } from "@/features/auth/account-tabs";

export const metadata: Metadata = {
  title: "Mon espace",
  description:
    "Consulte tes inscriptions, gère ton profil et accède à tes sessions Archipelago.",
};

export default function AccountPage() {
  return (
    <div className="mx-auto grid max-w-3xl gap-10">
      <header>
        <p className="mb-3 text-sm font-semibold uppercase tracking-[0.18em] text-accent-text">
          Compte ArchiLAN
        </p>
        <h1 className="font-heading text-4xl font-bold leading-tight text-foreground md:text-5xl">
          Mon espace.
        </h1>
      </header>

      <AccountTabs />
    </div>
  );
}
