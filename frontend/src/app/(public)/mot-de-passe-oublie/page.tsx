import type { Metadata } from "next";
import Link from "next/link";
import { ForgotPasswordForm } from "@/features/auth/forgot-password-form";

export const metadata: Metadata = {
  title: "Mot de passe oublié",
  description: "Réinitialise ton mot de passe ArchiLAN.",
  openGraph: {
    title: "Mot de passe oublié - ArchiLAN",
  },
};

export default function ForgotPasswordPage() {
  return (
    <div className="flex min-h-[60vh] flex-col items-center justify-center">
      <div className="w-full max-w-md">
        <div className="mb-8">
          <p className="mb-3 text-sm font-semibold uppercase tracking-[0.18em] text-accent-text">
            Accès au compte
          </p>
          <h1 className="font-heading text-3xl font-bold text-foreground">
            Mot de passe oublié
          </h1>
          <p className="mt-3 text-base leading-7 text-muted-foreground">
            Renseigne ton adresse email et nous t&apos;enverrons un lien pour
            choisir un nouveau mot de passe.
          </p>
        </div>

        <ForgotPasswordForm />

        <p className="mt-6 text-center text-sm text-muted-foreground">
          Tu te souviens de ton mot de passe ?{" "}
          <Link
            className="text-accent-text hover:text-accent-text-hover"
            href="/connexion"
          >
            Se connecter
          </Link>
        </p>
      </div>
    </div>
  );
}
