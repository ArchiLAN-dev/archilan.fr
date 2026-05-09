import type { Metadata } from "next";
import { AccountProfile } from "@/features/auth/account-profile";
import { AccountRegistrations } from "@/features/auth/account-registrations";

export const metadata: Metadata = {
  title: "Mon espace",
  description: "Consulte tes inscriptions, gère ton profil et accède à tes sessions Archipelago.",
};

export default function AccountPage() {
  return (
    <div className="mx-auto grid max-w-3xl gap-12">
      <section>
        <p className="mb-4 text-sm font-semibold uppercase tracking-[0.18em] text-accent-text">
          Compte ArchiLAN
        </p>
        <h1 className="font-heading text-4xl font-bold leading-tight text-foreground md:text-5xl">
          Mon espace.
        </h1>
        <p className="mt-5 text-lg leading-8 text-muted-foreground">
          Retrouve tes inscriptions, tes sessions Archipelago et tes informations de profil.
        </p>
      </section>

      <AccountRegistrations />

      <AccountProfile />
    </div>
  );
}
