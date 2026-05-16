import type { Metadata } from "next";
import Link from "next/link";
import { env } from "@/lib/env";
import { isConfirmEmailError } from "@/features/auth/auth-api";

export const metadata: Metadata = {
  title: "Confirmation d'email",
  description: "Confirmation de ton adresse email ArchiLAN.",
  openGraph: {
    title: "Confirmation d'email — ArchiLAN",
  },
};

type PageProps = {
  searchParams: Promise<{ [key: string]: string | string[] | undefined }>;
};

export default async function EmailConfirmationPage({ searchParams }: PageProps) {
  const { token } = await searchParams;
  const confirmed = await tryConfirm(token);

  if (confirmed) {
    return (
      <div className="flex min-h-[60vh] flex-col items-center justify-center">
        <div className="w-full max-w-md text-center">
          <p className="mb-3 text-sm font-semibold uppercase tracking-[0.18em] text-accent-text">
            Compte activé
          </p>
          <h1 className="font-heading text-3xl font-bold text-foreground">
            Email confirmé !
          </h1>
          <p className="mt-3 text-base leading-7 text-muted-foreground">
            Ton adresse email est validée. Tu peux maintenant t&apos;inscrire aux événements ArchiLAN.
          </p>
          <div className="mt-8 flex flex-col gap-3 sm:flex-row sm:justify-center">
            <Link
              className="inline-flex min-h-12 items-center justify-center rounded bg-accent px-5 font-semibold text-white transition-colors hover:bg-accent-hover"
              href="/connexion"
            >
              Se connecter
            </Link>
            <Link
              className="inline-flex min-h-12 items-center justify-center rounded border border-border px-5 font-semibold text-foreground transition-colors hover:bg-surface"
              href="/evenements"
            >
              Voir les événements
            </Link>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="flex min-h-[60vh] flex-col items-center justify-center">
      <div className="w-full max-w-md text-center">
        <p className="mb-3 text-sm font-semibold uppercase tracking-[0.18em] text-accent-text">
          Lien invalide
        </p>
        <h1 className="font-heading text-3xl font-bold text-foreground">
          Ce lien a expiré
        </h1>
        <p className="mt-3 text-base leading-7 text-muted-foreground">
          Ce lien de confirmation est invalide ou a déjà été utilisé. Connecte-toi
          pour en recevoir un nouveau depuis ton espace compte.
        </p>
        <div className="mt-8">
          <Link
            className="inline-flex min-h-12 items-center justify-center rounded bg-accent px-5 font-semibold text-white transition-colors hover:bg-accent-hover"
            href="/connexion"
          >
            Se connecter
          </Link>
        </div>
      </div>
    </div>
  );
}

async function tryConfirm(token: string | string[] | undefined): Promise<boolean> {
  if (typeof token !== "string" || token.trim() === "") {
    return false;
  }

  try {
    const res = await fetch(
      `${env.apiBaseUrl}/auth/confirm-email?token=${encodeURIComponent(token)}`,
      { cache: "no-store" },
    );

    if (res.status === 204) {
      return true;
    }

    if (res.status === 400) {
      const body: unknown = await res.json();
      if (isConfirmEmailError(body) && body.error.code === "invalid_confirmation_token") {
        return false;
      }
    }

    return false;
  } catch {
    return false;
  }
}
