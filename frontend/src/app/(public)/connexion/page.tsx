import type { Metadata } from "next";
import { LoginForm } from "@/features/auth/login-form";
import { PasswordResetBanner } from "@/features/auth/password-reset-banner";

export const metadata: Metadata = {
  title: "Connexion",
  description: "Connexion sécurisée au compte ArchiLAN.",
};

type LoginPageProps = {
  searchParams: Promise<{ [key: string]: string | string[] | undefined }>;
};

export default async function LoginPage({ searchParams }: LoginPageProps) {
  const { returnTo, reset, discord_error } = await searchParams;
  const safeReturnTo = safeInternalReturnTo(returnTo);
  const passwordWasReset = reset === "1";
  const discordError = typeof discord_error === "string" ? discord_error : undefined;

  return (
    <div className="flex min-h-[60vh] flex-col items-center justify-center">
      <div className="w-full max-w-md">
        <div className="mb-8">
          <p className="mb-3 text-sm font-semibold uppercase tracking-[0.18em] text-accent-text">
            Bon retour
          </p>
          <h1 className="font-heading text-3xl font-bold text-foreground">
            Connexion à ton compte
          </h1>
          <p className="mt-3 text-base leading-7 text-muted-foreground">
            Accède à ton espace pour t&apos;inscrire aux prochains événements ArchiLAN.
          </p>
        </div>

        {passwordWasReset && <PasswordResetBanner />}
        <LoginForm discordError={discordError} returnTo={safeReturnTo} />
      </div>
    </div>
  );
}

function safeInternalReturnTo(value: string | string[] | undefined) {
  if (typeof value !== "string") {
    return undefined;
  }

  return value.startsWith("/") && !value.startsWith("//") ? value : undefined;
}
