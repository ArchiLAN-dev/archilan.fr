import type { Metadata } from "next";

import { AccountShell } from "@/features/auth/account-shell";
import { RequireAuth } from "@/features/auth/require-auth";

export const metadata: Metadata = {
  title: { default: "Mon espace", template: "%s · Mon espace" },
  description:
    "Consulte tes inscriptions, gère ton profil et accède à tes sessions Archipelago.",
};

export default function AccountLayout({ children }: { children: React.ReactNode }) {
  return (
    <RequireAuth>
      <div className="mx-auto grid max-w-5xl gap-8">
        <header>
          <p className="mb-3 text-sm font-semibold uppercase tracking-[0.18em] text-accent-text">
            Compte ArchiLAN
          </p>
          <h1 className="font-heading text-4xl font-bold leading-tight text-foreground md:text-5xl">
            Mon espace
          </h1>
        </header>

        <AccountShell>{children}</AccountShell>
      </div>
    </RequireAuth>
  );
}
