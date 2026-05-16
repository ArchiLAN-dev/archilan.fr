import type { Metadata } from "next";
import Link from "next/link";
import { redirect } from "next/navigation";
import { ResetPasswordForm } from "@/features/auth/reset-password-form";

export const metadata: Metadata = {
  title: "Réinitialisation du mot de passe",
  description: "Choisis un nouveau mot de passe pour ton compte ArchiLAN.",
  openGraph: {
    title: "Réinitialisation du mot de passe — ArchiLAN",
  },
};

type PageProps = {
  searchParams: Promise<{ [key: string]: string | string[] | undefined }>;
};

export default async function ResetPasswordPage({ searchParams }: PageProps) {
  const { token } = await searchParams;

  if (typeof token !== "string" || token.trim() === "") {
    redirect("/mot-de-passe-oublie");
  }

  return (
    <div className="flex min-h-[60vh] flex-col items-center justify-center">
      <div className="w-full max-w-md">
        <div className="mb-8">
          <p className="mb-3 text-sm font-semibold uppercase tracking-[0.18em] text-accent-text">
            Accès au compte
          </p>
          <h1 className="font-heading text-3xl font-bold text-foreground">
            Nouveau mot de passe
          </h1>
          <p className="mt-3 text-base leading-7 text-muted-foreground">
            Choisis un nouveau mot de passe pour ton compte ArchiLAN.
          </p>
        </div>

        <ResetPasswordForm token={token} />

        <p className="mt-6 text-center text-sm text-muted-foreground">
          <Link
            className="text-accent-text hover:text-accent-text-hover"
            href="/connexion"
          >
            Retour à la connexion
          </Link>
        </p>
      </div>
    </div>
  );
}
